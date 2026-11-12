<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing\Concerns;

use Cbox\Relay\Client\Data\DnsRecord;
use Cbox\Relay\Client\Data\DomainReputation;
use Cbox\Relay\Client\Data\DomainTracking;
use Cbox\Relay\Client\Data\Mail;
use Cbox\Relay\Client\Data\MailRecipient;
use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Data\Page;
use Cbox\Relay\Client\Data\Problem;
use Cbox\Relay\Client\Data\SendingDomain;
use Cbox\Relay\Client\Data\Suppression;
use Cbox\Relay\Client\Data\SuppressionReason;
use Cbox\Relay\Client\Data\TrackingChanges;
use Cbox\Relay\Client\Exceptions\RelayConflict;
use Cbox\Relay\Client\Testing\Operation;
use Cbox\Relay\Client\Testing\RecordedCall;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;

/** Outbound mail, in memory: domains and suppressions are stored, mail is recorded and never leaves. */
trait FakesMail
{
    /** Where a domain's own host for tracked links points in the fake, as Relay's tracking host is in production. */
    public const string TRACKING_HOST = 'links.cboxrelay.com';

    /** @var array<string, array<string, SendingDomain>> by project, then id */
    private array $domains = [];

    /** @var array<string, array<string, Suppression>> by project, then address */
    private array $suppressions = [];

    /** @var array<string, array<string, Mail>> by project, then id */
    private array $mails = [];

    /** @var list<array{mail: OutgoingMail, project: string|null, id: string}> */
    private array $sentMail = [];

    /** @var list<string> ids of held mail that was released */
    private array $releasedMail = [];

    /** @var list<string> ids of held mail that was discarded */
    private array $discardedMail = [];

    public function listDomains(?string $project = null): array
    {
        $this->record(new RecordedCall(Operation::ListDomains, $project));

        return array_values($this->domains[$this->bucket($project)] ?? []);
    }

    public function createDomain(string $domain, ?string $idempotencyKey = null, ?string $project = null): SendingDomain
    {
        $this->record(new RecordedCall(Operation::CreateDomain, $project, $domain, idempotencyKey: $idempotencyKey));
        $bucket = $this->bucket($project);
        $fingerprint = $this->fingerprint(Operation::CreateDomain, $bucket, $domain);
        $replayed = $this->replayed($idempotencyKey, $fingerprint);

        if ($replayed instanceof SendingDomain) {
            return $replayed;
        }

        $domain = strtolower($domain);

        if (array_find($this->domains[$bucket] ?? [], static fn (SendingDomain $known): bool => $known->domain === $domain) !== null) {
            throw $this->invalid('domain', "The domain '{$domain}' is registered for this project already.");
        }

        $number = $this->nextNumber();

        $stored = new SendingDomain('sdm_fake_'.$number, $domain, 'pending', $this->now(), [
            new DnsRecord('TXT', 'relay'.$number.'._domainkey.'.$domain, 'v=DKIM1; k=rsa; p=fake', 'dkim', true),
            new DnsRecord('TXT', $domain, 'v=spf1 include:spf.cboxrelay.com ~all', 'spf', false),
            new DnsRecord('TXT', '_dmarc.'.$domain, 'v=DMARC1; p=none', 'dmarc', false),
        ]);

        return $this->remember($idempotencyKey, $fingerprint, $this->domains[$bucket][$stored->id] = $stored);
    }

    public function findDomain(string $id, ?string $project = null): ?SendingDomain
    {
        $this->record(new RecordedCall(Operation::FindDomain, $project, $id));

        return $this->domains[$this->bucket($project)][$id] ?? null;
    }

    public function updateDomain(string $id, TrackingChanges $tracking, ?string $project = null): SendingDomain
    {
        $this->record(new RecordedCall(Operation::UpdateDomain, $project, $id, subject: $tracking));
        $bucket = $this->bucket($project);
        $domain = $this->domainIn($bucket, $id);
        $host = $tracking->clearHost && $tracking->host === null ? null : strtolower($tracking->host ?? $domain->tracking->host ?? '');
        $host = $host === '' ? null : $host;

        if ($host !== null && preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/', $host) !== 1) {
            throw $this->invalid('tracking.host', 'That is not a host name.');
        }

        // A host of the domain's own is a record to publish; without one the record goes.
        $records = array_values(array_filter($domain->records, static fn (DnsRecord $record): bool => $record->purpose !== 'tracking'));

        if ($host !== null) {
            $records[] = new DnsRecord('CNAME', $host, self::TRACKING_HOST, 'tracking', false);
        }

        return $this->domains[$bucket][$id] = new SendingDomain(
            $domain->id, $domain->domain, $domain->status, $domain->createdAt, $records, $domain->verifiedAt, $domain->checkedAt,
            new DomainTracking($tracking->clicks ?? $domain->tracking->clicks, $host),
        );
    }

    public function verifyDomain(string $id, ?string $project = null): SendingDomain
    {
        $this->record(new RecordedCall(Operation::VerifyDomain, $project, $id));
        $bucket = $this->bucket($project);
        $domain = $this->domainIn($bucket, $id);

        // Found: verified. Gone from a verified domain: failed. Never found: still pending.
        $status = match (true) {
            $this->dnsPublished => 'verified',
            $domain->status === 'pending' => 'pending',
            default => 'failed',
        };

        return $this->domains[$bucket][$id] = new SendingDomain(
            $domain->id, $domain->domain, $status, $domain->createdAt, $domain->records,
            $status === 'verified' ? ($domain->verifiedAt ?? $this->now()) : $domain->verifiedAt, $this->now(), $domain->tracking,
        );
    }

    public function domainReputation(string $id, ?string $project = null): DomainReputation
    {
        $this->record(new RecordedCall(Operation::DomainReputation, $project, $id));
        $bucket = $this->bucket($project);
        $domain = $this->domainIn($bucket, $id);
        $counts = ['sent' => 0, 'bounced' => 0, 'complained' => 0];

        foreach ($this->mails[$bucket] ?? [] as $mail) {
            if (! str_ends_with(strtolower($mail->from), '@'.$domain->domain)) {
                continue;
            }

            // Every recipient the mail server took counts as sent, whatever came back after.
            foreach ($mail->recipients as $recipient) {
                if (in_array($recipient->status, ['sent', 'delivered', 'bounced', 'complained'], true)) {
                    $counts['sent']++;
                }

                if ($recipient->status === 'bounced' || $recipient->status === 'complained') {
                    $counts[$recipient->status]++;
                }
            }
        }

        $taken = $counts['sent'];

        return new DomainReputation(
            $domain->id, $domain->domain, 7, $taken, $counts['bounced'], $counts['complained'],
            $taken === 0 ? null : $counts['bounced'] / $taken,
            $taken === 0 ? null : $counts['complained'] / $taken,
        );
    }

    public function deleteDomain(string $id, ?string $project = null): void
    {
        $this->record(new RecordedCall(Operation::DeleteDomain, $project, $id));
        $bucket = $this->bucket($project);
        $this->domainIn($bucket, $id);

        unset($this->domains[$bucket][$id]);
    }

    public function listSuppressions(?int $limit = null, ?string $cursor = null, ?string $project = null): Page
    {
        $this->record(new RecordedCall(Operation::ListSuppressions, $project, limit: $limit, cursor: $cursor));

        return $this->page(array_reverse(array_values($this->suppressions[$this->bucket($project)] ?? [])), $limit, $cursor);
    }

    public function createSuppression(string $address, ?SuppressionReason $reason = null, ?string $idempotencyKey = null, ?string $project = null): Suppression
    {
        $this->record(new RecordedCall(Operation::CreateSuppression, $project, $address, $reason?->value, idempotencyKey: $idempotencyKey));
        $bucket = $this->bucket($project);
        $fingerprint = $this->fingerprint(Operation::CreateSuppression, $bucket, [$address, $reason]);
        $replayed = $this->replayed($idempotencyKey, $fingerprint);

        if ($replayed instanceof Suppression) {
            return $replayed;
        }

        $address = strtolower($address);
        $known = $this->suppressions[$bucket][$address] ?? null;

        // Listed already: its first reason stands.
        if ($known !== null) {
            return $this->remember($idempotencyKey, $fingerprint, $known);
        }

        $stored = new Suppression($address, ($reason ?? SuppressionReason::Manual)->value, $this->now());
        $this->suppressions[$bucket][$address] = $stored;

        return $this->remember($idempotencyKey, $fingerprint, new Suppression($stored->address, $stored->reason, $stored->createdAt, created: true));
    }

    public function deleteSuppression(string $address, ?string $project = null): void
    {
        $this->record(new RecordedCall(Operation::DeleteSuppression, $project, $address));
        $bucket = $this->bucket($project);
        $address = strtolower($address);

        if (! isset($this->suppressions[$bucket][$address])) {
            throw $this->missing('suppression_not_found', 'That address is not on the suppression list of this project.');
        }

        unset($this->suppressions[$bucket][$address]);
    }

    public function sendMail(OutgoingMail $mail, ?string $idempotencyKey = null, ?string $project = null): Mail
    {
        $this->record(new RecordedCall(Operation::SendMail, $project, idempotencyKey: $idempotencyKey, subject: $mail));
        $bucket = $this->bucket($project);
        $fingerprint = $this->fingerprint(Operation::SendMail, $bucket, $mail);
        $replayed = $this->replayed($idempotencyKey, $fingerprint);

        if ($replayed instanceof Mail) {
            return $replayed;
        }

        $this->guardMail($bucket, $mail);

        $recipients = [];

        foreach (['to' => $mail->to, 'cc' => $mail->cc, 'bcc' => $mail->bcc] as $type => $addresses) {
            foreach ($addresses as $address) {
                $suppressed = isset($this->suppressions[$bucket][strtolower($address)]);
                $recipients[] = new MailRecipient($address, $type, $suppressed ? 'suppressed' : 'queued', $suppressed ? 'The address is on the suppression list.' : null);
            }
        }

        $blocked = array_all($recipients, static fn (MailRecipient $recipient): bool => $recipient->status === 'suppressed');

        $stored = new Mail(
            id: 'msg_fake_'.$this->nextNumber(),
            status: $blocked ? 'blocked' : 'queued',
            from: $mail->from->address,
            subject: $mail->subject,
            createdAt: $this->now(),
            reason: $blocked ? 'Every recipient is on the suppression list.' : null,
            verdicts: array_fill_keys(self::VERDICTS, 0.0),
            recipients: $recipients,
            // The mail's own answer wins; left out, the sending domain's setting decides.
            tracksClicks: $mail->trackClicks ?? $this->domainTracksClicks($bucket, $mail->from->address),
        );

        $this->mails[$bucket][$stored->id] = $stored;
        $this->sentMail[] = ['mail' => $mail, 'project' => $project, 'id' => $stored->id];

        return $this->remember($idempotencyKey, $fingerprint, $stored);
    }

    public function findMail(string $id, ?string $project = null): ?Mail
    {
        $this->record(new RecordedCall(Operation::FindMail, $project, $id));

        return $this->mails[$this->bucket($project)][$id] ?? null;
    }

    public function releaseMail(string $id, ?string $project = null): Mail
    {
        $this->record(new RecordedCall(Operation::ReleaseMail, $project, $id));
        $bucket = $this->bucket($project);
        $mail = $this->heldMailIn($bucket, $id);

        // Queued as if it had not been held; whoever was suppressed since is left out.
        $recipients = array_map(fn (MailRecipient $recipient): MailRecipient => isset($this->suppressions[$bucket][strtolower($recipient->address)])
            ? new MailRecipient($recipient->address, $recipient->type, 'suppressed', 'The address is on the suppression list.')
            : $recipient, $mail->recipients);

        $this->releasedMail[] = $id;

        return $this->mails[$bucket][$id] = new Mail($mail->id, 'queued', $mail->from, $mail->subject, $mail->createdAt, null, $mail->verdicts, $recipients, $mail->sentAt, $mail->tracksClicks);
    }

    public function discardMail(string $id, ?string $project = null): Mail
    {
        $this->record(new RecordedCall(Operation::DiscardMail, $project, $id));
        $bucket = $this->bucket($project);
        $mail = $this->heldMailIn($bucket, $id);

        $this->discardedMail[] = $id;

        return $this->mails[$bucket][$id] = new Mail($mail->id, 'blocked', $mail->from, $mail->subject, $mail->createdAt, 'The held mail was discarded.', $mail->verdicts, $mail->recipients, $mail->sentAt, $mail->tracksClicks);
    }

    /**
     * Store a sending domain for a project (stored first if it is not there) as if it had
     * been registered and, by default, verified earlier. Records no call.
     */
    public function seedDomain(string $project, string $domain, string $status = 'verified'): SendingDomain
    {
        $bucket = ($this->project($project) ?? $this->seedProject($project))->id;
        $stored = new SendingDomain('sdm_fake_'.$this->nextNumber(), strtolower($domain), $status, $this->now(), verifiedAt: $status === 'verified' ? $this->now() : null);

        return $this->domains[$bucket][$stored->id] = $stored;
    }

    /**
     * Set a stored domain's click tracking as if it had been changed earlier. Records no call.
     */
    public function seedTracking(string $domainId, bool $clicks = true, ?string $host = null): SendingDomain
    {
        foreach ($this->domains as $bucket => $domains) {
            $domain = $domains[$domainId] ?? null;

            if ($domain !== null) {
                return $this->domains[$bucket][$domainId] = new SendingDomain(
                    $domain->id, $domain->domain, $domain->status, $domain->createdAt, $domain->records, $domain->verifiedAt, $domain->checkedAt,
                    new DomainTracking($clicks, $host),
                );
            }
        }

        throw new InvalidArgumentException("The fake holds no sending domain '{$domainId}'.");
    }

    /** Put an address on a project's suppression list (the project is stored first if it is not there). Records no call. */
    public function seedSuppression(string $project, string $address, SuppressionReason $reason = SuppressionReason::Manual, ?string $source = null): Suppression
    {
        $bucket = ($this->project($project) ?? $this->seedProject($project))->id;

        return $this->suppressions[$bucket][strtolower($address)] = new Suppression(strtolower($address), $reason->value, $this->now(), $source);
    }

    /** Set what became of a mail, as the queue would have (`sent`, `failed`, `quarantined`, …). Every queued recipient follows a `sent` or `failed`. */
    public function markMail(string $id, string $status, ?string $reason = null): static
    {
        foreach ($this->mails as $bucket => $mails) {
            $mail = $mails[$id] ?? null;

            if ($mail !== null) {
                $this->mails[$bucket][$id] = new Mail(
                    $mail->id, $status, $mail->from, $mail->subject, $mail->createdAt, $reason, $mail->verdicts,
                    array_map(static fn (MailRecipient $recipient): MailRecipient => $recipient->status === 'queued' && in_array($status, ['sent', 'failed'], true)
                        ? new MailRecipient($recipient->address, $recipient->type, $status, $reason)
                        : $recipient, $mail->recipients),
                    $status === 'sent' ? $this->now() : $mail->sentAt,
                    $mail->tracksClicks,
                );
            }
        }

        return $this;
    }

    /**
     * Set what became of one recipient of a mail, as feedback from the receiving side
     * would have (`delivered`, `bounced`, `complained`, `sent`, `failed`). It shows in the domain's reputation.
     */
    public function markRecipient(string $mailId, string $address, string $status, ?string $detail = null): static
    {
        foreach ($this->mails as $bucket => $mails) {
            $mail = $mails[$mailId] ?? null;

            if ($mail !== null) {
                $this->mails[$bucket][$mailId] = new Mail(
                    $mail->id, $mail->status, $mail->from, $mail->subject, $mail->createdAt, $mail->reason, $mail->verdicts,
                    array_map(static fn (MailRecipient $recipient): MailRecipient => strtolower($recipient->address) === strtolower($address)
                        ? new MailRecipient($recipient->address, $recipient->type, $status, $detail)
                        : $recipient, $mail->recipients),
                    $mail->sentAt,
                    $mail->tracksClicks,
                );
            }
        }

        return $this;
    }

    /**
     * The mails that were handed over, in order.
     *
     * @return list<OutgoingMail>
     */
    public function sentMail(): array
    {
        return array_map(static fn (array $sent): OutgoingMail => $sent['mail'], $this->sentMail);
    }

    /**
     * A mail was handed to Relay (and matches `$where`).
     *
     * @param  (callable(OutgoingMail, string|null): bool)|null  $where  given the mail and the project it was sent for
     */
    public function assertMailSent(?callable $where = null): void
    {
        $matching = array_filter($this->sentMail, static fn (array $sent): bool => $where === null || $where($sent['mail'], $sent['project']));

        Assert::assertNotEmpty($matching, $where === null
            ? 'No mail was sent through Cbox Relay.'
            : 'No mail matching the condition was sent through Cbox Relay.');
    }

    public function assertMailSentTimes(int $times): void
    {
        Assert::assertCount($times, $this->sentMail, "Cbox Relay was not handed {$times} mail(s).");
    }

    public function assertNoMailSent(): void
    {
        Assert::assertCount(0, $this->sentMail, 'Mail was sent through Cbox Relay.');
    }

    /** The stored sending domain `$id` has click tracking on (or, with false, off). */
    public function assertDomainTracksClicks(string $id, bool $clicks = true): void
    {
        foreach ($this->domains as $domains) {
            if (isset($domains[$id])) {
                Assert::assertSame($clicks, $domains[$id]->tracking->clicks, "The sending domain '{$id}' ".($clicks ? 'does not track' : 'tracks').' clicks in Cbox Relay.');

                return;
            }
        }

        Assert::fail("Cbox Relay holds no sending domain '{$id}'.");
    }

    /** The held mail `$id` was released. */
    public function assertMailReleased(string $id): void
    {
        Assert::assertContains($id, $this->releasedMail, "The held mail '{$id}' was not released through Cbox Relay.");
    }

    /** The held mail `$id` was discarded. */
    public function assertMailDiscarded(string $id): void
    {
        Assert::assertContains($id, $this->discardedMail, "The held mail '{$id}' was not discarded through Cbox Relay.");
    }

    /** A mail that exists in the project and is held; anything else is the 404 or the 409 the real service answers. */
    private function heldMailIn(string $bucket, string $id): Mail
    {
        $mail = $this->mails[$bucket][$id] ?? throw $this->missing('mail_not_found', 'No mail with that id exists in this project.');

        if ($mail->status !== 'quarantined') {
            throw new RelayConflict('Cbox Relay answered 409 Conflict: The mail is not held.', new Problem(409, 'Conflict', 'The mail is not held.', self::PROBLEMS.'mail_not_held'));
        }

        return $mail;
    }

    /** What the real service refuses a mail for, as far as the fake can know. */
    /** Whether the project sends from the domain: registered and verified. */
    private function sendsFrom(string $bucket, string $domain): bool
    {
        return array_any($this->domains[$bucket] ?? [], static fn (SendingDomain $known): bool => $known->domain === strtolower($domain) && $known->verified());
    }

    /** What the sending domain of an address says about click tracking; false when the project has no such domain. */
    private function domainTracksClicks(string $bucket, string $address): bool
    {
        $at = strrpos($address, '@');
        $domain = $at === false ? '' : strtolower(substr($address, $at + 1));

        return array_any($this->domains[$bucket] ?? [], static fn (SendingDomain $known): bool => $known->domain === $domain && $known->tracking->clicks);
    }

    private function guardMail(string $bucket, OutgoingMail $mail): void
    {
        if ($mail->to === []) {
            throw $this->invalid('to', 'A mail needs at least one recipient.');
        }

        if (($mail->html === null || $mail->html === '') && ($mail->text === null || $mail->text === '')) {
            throw $this->invalid('html', 'At least one of html and text is required.');
        }

        if (count($mail->to) + count($mail->cc) + count($mail->bcc) > 50) {
            throw $this->invalid('to', 'A mail may have 50 recipients.');
        }

        if (count($mail->attachments) > 20) {
            throw $this->invalid('attachments', 'A mail may have 20 attachments.');
        }

        foreach (array_keys($mail->headers) as $name) {
            if (! OutgoingMail::allowsHeader($name)) {
                throw $this->invalid('headers', "The header \"{$name}\" cannot be set.");
            }
        }

        // Once a project has sending domains, the sender must be at a verified one, as on the real service.
        $domains = $this->domains[$bucket] ?? [];
        $sender = strtolower(substr((string) strrchr($mail->from->address, '@'), 1));

        if ($domains !== [] && array_find($domains, static fn (SendingDomain $domain): bool => $domain->domain === $sender && $domain->verified()) === null) {
            throw $this->invalid('from.address', "The domain '{$sender}' is not a verified sending domain of this project.");
        }
    }

    private function domainIn(string $bucket, string $id): SendingDomain
    {
        return $this->domains[$bucket][$id] ?? throw $this->missing('domain_not_found', 'No sending domain with that id exists in this project.');
    }
}
