<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing\Concerns;

use Cbox\Relay\Client\Data\DnsRecord;
use Cbox\Relay\Client\Data\InboundAttachment;
use Cbox\Relay\Client\Data\InboundMessage;
use Cbox\Relay\Client\Data\InboundRoute;
use Cbox\Relay\Client\Data\InboundRouteType;
use Cbox\Relay\Client\Data\MailAddress;
use Cbox\Relay\Client\Data\Page;
use Cbox\Relay\Client\Testing\Operation;
use Cbox\Relay\Client\Testing\RecordedCall;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Assert;

/** Inbound mail, in memory: routes are really claimed, received mail is what the test seeded. */
trait FakesInbound
{
    /** The domain the fake's forwarding addresses are at, as Relay's own receiving domain is in production. */
    public const string FORWARDING_DOMAIN = 'inbound.cboxrelay.com';

    /** @var array<string, array<string, InboundRoute>> by project, then id */
    private array $inboundRoutes = [];

    /** @var array<string, InboundMessage> by id, each with its content */
    private array $inbound = [];

    /** @var list<string> ids of received mail that was erased */
    private array $erased = [];

    public function listInboundRoutes(?string $project = null): array
    {
        $this->record(new RecordedCall(Operation::ListInboundRoutes, $project));

        return array_values($this->inboundRoutes[$this->bucket($project)] ?? []);
    }

    public function createInboundRoute(InboundRouteType $type, ?string $domain = null, ?string $prefix = null, ?string $idempotencyKey = null, ?string $project = null, ?string $localPart = null, ?string $forwardFor = null): InboundRoute
    {
        $this->record(new RecordedCall(Operation::CreateInboundRoute, $project, $domain ?? $forwardFor, $prefix ?? $localPart, idempotencyKey: $idempotencyKey));
        $bucket = $this->bucket($project);
        $fingerprint = $this->fingerprint(Operation::CreateInboundRoute, $bucket, [$type, $domain, $prefix, $localPart, $forwardFor]);
        $replayed = $this->replayed($idempotencyKey, $fingerprint);

        if ($replayed instanceof InboundRoute) {
            return $replayed;
        }

        if ($forwardFor !== null && $type !== InboundRouteType::Address) {
            throw $this->invalid('forward_for', 'Only an address can stand in for another.');
        }

        if ($type === InboundRouteType::Prefix && ($prefix === null || $prefix === '')) {
            throw $this->invalid('prefix', 'A prefix route needs a prefix.');
        }

        $number = $this->nextNumber();
        $forwarding = $type === InboundRouteType::Address && $forwardFor !== null && ($domain === null || $domain === '');

        if ($forwarding) {
            // An address of Relay's own that stands in for the customer's: a readable name and a tail nobody can guess.
            $forwardFor = strtolower($forwardFor);
            $domain = self::FORWARDING_DOMAIN;
            $localPart = trim((string) preg_replace('/[^a-z0-9]+/', '-', $forwardFor), '-').'-fake'.$number;
        }

        if ($domain === null || $domain === '') {
            throw $this->invalid('domain', 'A route needs a domain.');
        }

        if ($type === InboundRouteType::Address && ($localPart === null || $localPart === '')) {
            throw $this->invalid('local_part', 'An address needs the part before the @.');
        }

        $domain = strtolower($domain);
        $pattern = match ($type) {
            InboundRouteType::Address => strtolower((string) $localPart).'@'.$domain,
            InboundRouteType::Prefix => $prefix.'*@'.$domain,
            default => '*@'.$domain,
        };

        foreach ($this->inboundRoutes as $routes) {
            if (array_find($routes, static fn (InboundRoute $route): bool => $route->pattern === $pattern) !== null) {
                throw $this->invalid('domain', "The pattern '{$pattern}' is taken.");
            }
        }

        // A name under the shared domain and a forwarding address work at once, and so does anything
        // at a domain the project already sends from; the others wait for DNS or for the operator.
        $own = in_array($type, [InboundRouteType::Domain, InboundRouteType::Address], true) && ! $forwarding;
        $active = $type === InboundRouteType::Subdomain || $forwarding || ($own && $this->sendsFrom($bucket, $domain));

        $route = new InboundRoute(
            id: 'irt_fake_'.$number,
            type: $type->value,
            domain: $domain,
            pattern: $pattern,
            status: $active ? 'active' : 'pending',
            createdAt: $this->now(),
            prefix: $type === InboundRouteType::Prefix ? $prefix : null,
            verification: $own && ! $active ? new DnsRecord('TXT', '_relay-challenge.'.$domain, 'relay-verify=fake_'.$number) : null,
            verifiedAt: $active ? $this->now() : null,
            address: $type === InboundRouteType::Address ? $pattern : null,
            forwardFor: $forwarding ? $forwardFor : null,
        );

        $this->inboundRoutes[$bucket][$route->id] = $route;

        return $this->remember($idempotencyKey, $fingerprint, $route);
    }

    public function verifyInboundRoute(string $id, ?string $project = null): InboundRoute
    {
        $this->record(new RecordedCall(Operation::VerifyInboundRoute, $project, $id));
        $bucket = $this->bucket($project);
        $route = $this->inboundRouteIn($bucket, $id);

        // Only a domain of the customer's own is proven by DNS; a prefix waits for the operator whatever is asked.
        if ($route->verification === null || $route->active() || ! $this->dnsPublished) {
            return $route;
        }

        return $this->inboundRoutes[$bucket][$id] = $this->activated($route);
    }

    public function deleteInboundRoute(string $id, ?string $project = null): void
    {
        $this->record(new RecordedCall(Operation::DeleteInboundRoute, $project, $id));
        $bucket = $this->bucket($project);
        $this->inboundRouteIn($bucket, $id);

        unset($this->inboundRoutes[$bucket][$id]);
    }

    public function listInbound(?int $limit = null, ?string $cursor = null, ?string $project = null): Page
    {
        $this->record(new RecordedCall(Operation::ListInbound, $project, limit: $limit, cursor: $cursor));
        $bucket = $this->bucket($project);

        $messages = array_values(array_filter($this->inbound, static fn (InboundMessage $message): bool => $bucket === '' || $message->projectId === $bucket));

        usort($messages, static fn (InboundMessage $a, InboundMessage $b): int => $b->receivedAt <=> $a->receivedAt);

        // Summaries: no content, and no links on the attachments.
        return $this->page(array_map(static fn (InboundMessage $message): InboundMessage => new InboundMessage(
            $message->id, $message->projectId, $message->status, $message->recipient, $message->envelopeFrom, $message->size, $message->receivedAt,
            $message->kind, $message->from, $message->subject, $message->messageId, $message->inReplyTo, $message->authentication,
            array_map(static fn (InboundAttachment $attachment): InboundAttachment => new InboundAttachment($attachment->id, $attachment->name, $attachment->type, $attachment->size, $attachment->inline, $attachment->contentId), $message->attachments),
        ), $messages), $limit, $cursor);
    }

    public function findInbound(string $id, ?string $project = null): ?InboundMessage
    {
        $this->record(new RecordedCall(Operation::FindInbound, $project, $id));
        $bucket = $this->bucket($project);
        $message = $this->inbound[$id] ?? null;

        return $message === null || ($bucket !== '' && $message->projectId !== $bucket) ? null : $message;
    }

    public function deleteInbound(string $id, ?string $idempotencyKey = null, ?string $project = null): void
    {
        $this->record(new RecordedCall(Operation::DeleteInbound, $project, $id, idempotencyKey: $idempotencyKey));
        $bucket = $this->bucket($project);
        $message = $this->inbound[$id] ?? null;

        if ($message === null || ($bucket !== '' && $message->projectId !== $bucket)) {
            throw $this->missing('inbound_not_found', 'No received mail with that id exists.');
        }

        unset($this->inbound[$id]);
        $this->erased[] = $id;
    }

    /**
     * Store a received, parsed mail for a project (stored first if it is not there),
     * without recording a call. Each attachment gets a link when the mail is fetched.
     *
     * @param  list<InboundAttachment>  $attachments
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $authentication
     */
    public function seedInbound(
        string $project,
        string $recipient = 'hello@example.test',
        ?string $from = 'ann@example.com',
        ?string $subject = 'Hello',
        string $text = 'Hello.',
        string $html = '',
        string $kind = 'mail',
        array $attachments = [],
        array $headers = [],
        array $authentication = ['spf' => 'pass', 'dkim' => 'pass', 'dmarc' => 'pass'],
        ?DateTimeInterface $receivedAt = null,
        string $status = 'parsed',
    ): InboundMessage {
        $owner = $this->project($project) ?? $this->seedProject($project);
        $id = 'inb_fake_'.$this->nextNumber();

        return $this->inbound[$id] = new InboundMessage(
            id: $id,
            projectId: $owner->id,
            status: $status,
            recipient: $recipient,
            envelopeFrom: $kind === 'bounce' ? '' : (string) $from,
            size: strlen($text) + strlen($html),
            receivedAt: $receivedAt === null ? $this->now() : DateTimeImmutable::createFromInterface($receivedAt),
            kind: $kind,
            from: $from === null ? null : new MailAddress($from),
            subject: $subject,
            messageId: '<'.$id.'@example.test>',
            authentication: $authentication,
            attachments: array_map(static fn (InboundAttachment $attachment): InboundAttachment => new InboundAttachment(
                $attachment->id, $attachment->name, $attachment->type, $attachment->size, $attachment->inline, $attachment->contentId,
                $attachment->url ?? 'https://relay.test/fake/attachments/'.$attachment->id,
            ), $attachments),
            text: $text,
            html: $html,
            headers: $headers,
        );
    }

    /** Make a pending inbound route active, as DNS being found or Relay's operator approving a prefix would. */
    public function activateInboundRoute(string $id): static
    {
        foreach ($this->inboundRoutes as $bucket => $routes) {
            if (isset($routes[$id])) {
                $this->inboundRoutes[$bucket][$id] = $this->activated($routes[$id]);
            }
        }

        return $this;
    }

    public function assertInboundErased(string $id): void
    {
        Assert::assertContains($id, $this->erased, "The received mail '{$id}' was not erased from Cbox Relay.");
    }

    /**
     * A route for that pattern (`*@acme.dk`) is claimed (and matches `$where`).
     *
     * @param  (callable(InboundRoute): bool)|null  $where
     */
    public function assertInboundRouteRegistered(string $pattern, ?callable $where = null): void
    {
        $matching = [];

        foreach ($this->inboundRoutes as $routes) {
            foreach ($routes as $route) {
                if ($route->pattern === $pattern && ($where === null || $where($route))) {
                    $matching[] = $route;
                }
            }
        }

        Assert::assertNotEmpty($matching, "No inbound route '{$pattern}'".($where === null ? '' : ' matching the condition').' is claimed with Cbox Relay.');
    }

    private function inboundRouteIn(string $bucket, string $id): InboundRoute
    {
        return $this->inboundRoutes[$bucket][$id] ?? throw $this->missing('inbound_route_not_found', 'No inbound route with that id exists in this project.');
    }

    private function activated(InboundRoute $route): InboundRoute
    {
        return new InboundRoute($route->id, $route->type, $route->domain, $route->pattern, 'active', $route->createdAt, $route->prefix, null, $this->now(), $route->address, $route->forwardFor);
    }
}
