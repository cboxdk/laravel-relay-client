<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Facades;

use Cbox\Relay\Client\Contracts\EventPublisher;
use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Publishing\ImmediateEventPublisher;
use Cbox\Relay\Client\Relay as RelayManager;
use Cbox\Relay\Client\Testing\FakeRelay;
use Cbox\Relay\Client\Webhooks\WebhookRoutes;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use LogicException;

/**
 * @method static \Cbox\Relay\Client\Data\Identity whoami()
 * @method static \Cbox\Relay\Client\Projects projects()
 * @method static \Cbox\Relay\Client\Data\Page<\Cbox\Relay\Client\Data\Delivery> deliveries(?\Cbox\Relay\Client\Data\DeliveryFilter $filter = null, ?int $limit = null, ?string $cursor = null)
 * @method static \Cbox\Relay\Client\EventTypes eventTypes()
 * @method static \Cbox\Relay\Client\Endpoints endpoints()
 * @method static \Cbox\Relay\Client\Events events()
 * @method static string publish(string $type, array<string, mixed> $payload = [], ?string $idempotencyKey = null, ?\DateTimeInterface $occurredAt = null, ?string $subject = null)
 * @method static \Cbox\Relay\Client\Data\Published publishNow(string $type, array<string, mixed> $payload = [], ?string $idempotencyKey = null, ?\DateTimeInterface $occurredAt = null, ?string $subject = null)
 * @method static list<\Cbox\Relay\Client\Data\Published> publishBatch(list<\Cbox\Relay\Client\Data\NewEvent> $events)
 * @method static \Cbox\Relay\Client\Data\Erasure eraseSubject(string $subject)
 * @method static \Cbox\Relay\Client\Sources sources()
 * @method static \Cbox\Relay\Client\Data\Judgment judge(?string $html = null, ?string $text = null, ?string $subject = null, \Cbox\Relay\Client\Data\MailAddress|string|null $from = null, ?\Cbox\Relay\Client\Data\TemplateReference $template = null, ?\Cbox\Relay\Client\Data\JudgeKind $kind = null, ?\Cbox\Relay\Client\Data\Direction $direction = null, array<string, string> $headers = [], ?string $idempotencyKey = null)
 * @method static ?\Cbox\Relay\Client\Data\Judgment judgment(string $id)
 * @method static \Cbox\Relay\Client\InboundRoutes inboundRoutes()
 * @method static \Cbox\Relay\Client\Inbound inbound()
 * @method static string pushKey()
 * @method static \Cbox\Relay\Client\PushSubscriptions pushSubscriptions()
 * @method static \Cbox\Relay\Client\Data\PushMessage push(string $subscription, string|array<string, mixed> $payload, ?int $ttl = null, ?\Cbox\Relay\Client\Data\PushUrgency $urgency = null, ?string $topic = null, ?string $idempotencyKey = null)
 * @method static ?\Cbox\Relay\Client\Data\PushMessage pushMessage(string $id)
 * @method static \Cbox\Relay\Client\Domains domains()
 * @method static \Cbox\Relay\Client\Suppressions suppressions()
 * @method static \Cbox\Relay\Client\Data\Mail sendMail(\Cbox\Relay\Client\Data\OutgoingMail $mail, ?string $idempotencyKey = null)
 * @method static list<\Cbox\Relay\Client\Data\MailBatchResult> sendMailBatch(list<\Cbox\Relay\Client\Data\OutgoingMail> $mails, ?string $idempotencyKey = null)
 * @method static \Cbox\Relay\Client\Data\Page<\Cbox\Relay\Client\Data\Mail> mails(?\Cbox\Relay\Client\Data\MailFilter $filter = null, ?int $limit = null, ?string $cursor = null)
 * @method static ?\Cbox\Relay\Client\Data\Mail mail(string $id)
 * @method static \Cbox\Relay\Client\Data\Mail releaseMail(string $id)
 * @method static \Cbox\Relay\Client\Data\Mail discardMail(string $id)
 * @method static \Cbox\Relay\Client\Data\Mail cancelMail(string $id)
 * @method static \Cbox\Relay\Client\Relay connection(string $name)
 * @method static ?string connectionName()
 * @method static \Cbox\Relay\Client\Relay forProject(string $ref)
 * @method static \Cbox\Relay\Client\Relay withoutProject()
 * @method static ?string project()
 * @method static \Cbox\Relay\Client\Contracts\RelayTransport transport()
 *
 * @see RelayManager
 */
class Relay extends Facade
{
    /**
     * Swap Cbox Relay for a {@see FakeRelay}: bound as the transport contract, so the
     * facade, an injected manager and an injected contract all talk to the same fake,
     * and `publish()` records on it at once instead of queueing a job. Nothing leaves
     * the process.
     */
    public static function fake(?FakeRelay $fake = null): FakeRelay
    {
        $app = static::getFacadeApplication();

        if (! $app instanceof Application) {
            throw new LogicException('Relay::fake() needs a booted application; use it from a Laravel or Testbench test case.');
        }

        $fake ??= new FakeRelay;

        $app->instance(RelayTransport::class, $fake);
        // publish() records on the fake at once instead of putting a job on the queue.
        $app->bind(EventPublisher::class, static fn (): EventPublisher => new ImmediateEventPublisher($fake));
        static::clearResolvedInstance(RelayManager::class);

        return $fake;
    }

    /**
     * Register the package's webhook endpoint from a routes file: `POST {path}` for the
     * default connection and `POST {path}/{connection}` for a named one. Each verified
     * delivery is handled once and dispatched as a Laravel event
     * (`Webhooks\Received\MailBounced`, …; every one implements `Contracts\ReceivedWebhook`).
     */
    public static function webhookRoutes(string $path = WebhookRoutes::DEFAULT_PATH): void
    {
        $app = static::getFacadeApplication();

        if (! $app instanceof Application) {
            throw new LogicException('Relay::webhookRoutes() needs a booted application: call it from a routes file.');
        }

        WebhookRoutes::register($app->make(Router::class), $path);
    }

    protected static function getFacadeAccessor(): string
    {
        return RelayManager::class;
    }
}
