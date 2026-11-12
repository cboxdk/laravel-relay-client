<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing\Concerns;

use Cbox\Relay\Client\Data\DnsRecord;
use Cbox\Relay\Client\Data\Mail;
use Cbox\Relay\Client\Data\MailRecipient;
use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Data\Page;
use Cbox\Relay\Client\Data\SendingDomain;
use Cbox\Relay\Client\Data\Suppression;
use Cbox\Relay\Client\Data\SuppressionReason;
use Cbox\Relay\Client\Testing\Operation;
use Cbox\Relay\Client\Testing\RecordedCall;
use PHPUnit\Framework\Assert;

/** Outbound mail, in memory: domains and suppressions are stored, mail is recorded and never leaves. */
trait FakesMail
{
    /** @var array<string, array<string, SendingDomain>> by scope, then id */
    private array $domains = [];

    /** @var array<string, array<string, Suppression>> by scope, then address */
    private array $suppressions = [];

    /** @var array<string, array<string, Mail>> by scope, then id */
    private array $mails = [];

    /** @var list<array{mail: OutgoingMail, scope: string|null, id: string}> */
    private array $sentMail = [];

    public function listDomains(?string $scope = null): array
    {
        $this->record(new RecordedCall(Operation::ListDomains, $scope));

        return array_values($this->domains[$this->bucket($scope)] ?? []);
    }

    public function createDomain(string $domain, ?string $idempotencyKey = null, ?string $scope = null): SendingDomain
    {
        $this->record(new RecordedCall(Operation::CreateDomain, $scope, $domain, idempotencyKey: $idempotencyKey));
        $bucket = $this->bucket($scope);
        $fingerprint = $this->fingerprint(Operation::CreateDomain, $bucket, $domain);
        $replayed = $this->replayed($idempotencyKey, $fingerprint);

        if ($replayed instanceof SendingDomain) {
            return $replayed;
        }

        $domain = strtolower($domain);

        if (array_find($this->domains[$bucket] ?? [], static fn (SendingDomain $known): bool => $known->domain === $domain) !== null) {
            throw $this->invalid('domain', "The domain '{$domain}' is registered for this scope already.");
        }

        $number = $this->nextNumber();

        $stored = new SendingDomain('sdm_fake_'.$number, $domain, 'pending', $this->now(), [
            new DnsRecord('TXT', 'relay'.$number.'._domainkey.'.$domain, 'v=DKIM1; k=rsa; p=fake', 'dkim', true),
            new DnsRecord('TXT', $domain, 'v=spf1 include:spf.cboxrelay.com ~all', 'spf', false),
            new DnsRecord('TXT', '_dmarc.'.$domain, 'v=DMARC1; p=none', 'dmarc', false),
        ]);

        return $this->remember($idempotencyKey, $fingerprint, $this->domains[$bucket][$stored->id] = $stored);
    }

    public function findDomain(string $id, ?string $scope = null): ?SendingDomain
    {
        $this->record(new RecordedCall(Operation::FindDomain, $scope, $id));

        return $this->domains[$this->bucket($scope)][$id] ?? null;
    }

    public function verifyDomain(string $id, ?string $scope = null): SendingDomain
    {
        $this->record(new RecordedCall(Operation::VerifyDomain, $scope, $id));
        $bucket = $this->bucket($scope);
        $domain = $this->domainIn($bucket, $id);

        // Found: verified. Gone from a verified domain: failed. Never found: still pending.
        $status = match (true) {
            $this->dnsPublished => 'verified',
            $domain->status === 'pending' => 'pending',
            default => 'failed',
        };

        return $this->domains[$bucket][$id] = new SendingDomain(
            $domain->id, $domain->domain, $status, $domain->createdAt, $domain->records,
            $status === 'verified' ? ($domain->verifiedAt ?? $this->now()) : $domain->verifiedAt, $this->now(),
        );
    }

    public function deleteDomain(string $id, ?string $scope = null): void
    {
        $this->record(new RecordedCall(Operation::DeleteDomain, $scope, $id));
        $bucket = $this->bucket($scope);
        $this->domainIn($bucket, $id);

        unset($this->domains[$bucket][$id]);
    }

    public function listSuppressions(?int $limit = null, ?string $cursor = null, ?string $scope = null): Page
    {
        $this->record(new RecordedCall(Operation::ListSuppressions, $scope, limit: $limit, cursor: $cursor));

        return $this->page(array_reverse(array_values($this->suppressions[$this->bucket($scope)] ?? [])), $limit, $cursor);
    }

    public function createSuppression(string $address, ?SuppressionReason $reason = null, ?string $idempotencyKey = null, ?string $scope = null): Suppression
    {
        $this->record(new RecordedCall(Operation::CreateSuppression, $scope, $address, $reason?->value, idempotencyKey: $idempotencyKey));
        $bucket = $this->bucket($scope);
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

    public function deleteSuppression(string $address, ?string $scope = null): void
    {
        $this->record(new RecordedCall(Operation::DeleteSuppression, $scope, $address));
        $bucket = $this->bucket($scope);
        $address = strtolower($address);

        if (! isset($this->suppressions[$bucket][$address])) {
            throw $this->missing('suppression_not_found', 'That address is not on the suppression list of this scope.');
        }

        unset($this->suppressions[$bucket][$address]);
    }

    public function sendMail(OutgoingMail $mail, ?string $idempotencyKey = null, ?string $scope = null): Mail
    {
        $this->record(new RecordedCall(Operation::SendMail, $scope, idempotencyKey: $idempotencyKey, subject: $mail));
        $bucket = $this->bucket($scope);
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
        );

        $this->mails[$bucket][$stored->id] = $stored;
        $this->sentMail[] = ['mail' => $mail, 'scope' => $scope, 'id' => $stored->id];

        return $this->remember($idempotencyKey, $fingerprint, $stored);
    }

    public function findMail(string $id, ?string $scope = null): ?Mail
    {
        $this->record(new RecordedCall(Operation::FindMail, $scope, $id));

        return $this->mails[$this->bucket($scope)][$id] ?? null;
    }

    /**
     * Store a sending domain for a scope (stored first if it is not there) as if it had
     * been registered and, by default, verified earlier. Records no call.
     */
    public function seedDomain(string $scope, string $domain, string $status = 'verified'): SendingDomain
    {
        $bucket = ($this->scope($scope) ?? $this->seedScope($scope))->id;
        $stored = new SendingDomain('sdm_fake_'.$this->nextNumber(), strtolower($domain), $status, $this->now(), verifiedAt: $status === 'verified' ? $this->now() : null);

        return $this->domains[$bucket][$stored->id] = $stored;
    }

    /** Put an address on a scope's suppression list (the scope is stored first if it is not there). Records no call. */
    public function seedSuppression(string $scope, string $address, SuppressionReason $reason = SuppressionReason::Manual, ?string $source = null): Suppression
    {
        $bucket = ($this->scope($scope) ?? $this->seedScope($scope))->id;

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
     * @param  (callable(OutgoingMail, string|null): bool)|null  $where  given the mail and the scope it was sent for
     */
    public function assertMailSent(?callable $where = null): void
    {
        $matching = array_filter($this->sentMail, static fn (array $sent): bool => $where === null || $where($sent['mail'], $sent['scope']));

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

    /** What the real service refuses a mail for, as far as the fake can know. */
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

        // Once a scope has sending domains, the sender must be at a verified one, as on the real service.
        $domains = $this->domains[$bucket] ?? [];
        $sender = strtolower(substr((string) strrchr($mail->from->address, '@'), 1));

        if ($domains !== [] && array_find($domains, static fn (SendingDomain $domain): bool => $domain->domain === $sender && $domain->verified()) === null) {
            throw $this->invalid('from.address', "The domain '{$sender}' is not a verified sending domain of this scope.");
        }
    }

    private function domainIn(string $bucket, string $id): SendingDomain
    {
        return $this->domains[$bucket][$id] ?? throw $this->missing('domain_not_found', 'No sending domain with that id exists in this scope.');
    }
}
