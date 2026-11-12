<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Mail;

use Cbox\Relay\Client\Exceptions\RelayForbidden;
use Cbox\Relay\Client\Exceptions\RelayNotConfigured;
use Cbox\Relay\Client\Exceptions\RelayRateLimited;
use Cbox\Relay\Client\Exceptions\RelayUnauthorized;
use Cbox\Relay\Client\Exceptions\RelayUnavailable;
use Cbox\Relay\Client\Relay;
use Closure;
use DateTimeImmutable;
use Exception;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Laravel's `relay` mail transport: every mail a mailer with `'transport' => 'relay'`
 * sends becomes one `POST /v1/mail`. The call is made where the mail is sent from: queue
 * your mailables as usual to keep it out of a request.
 *
 * Five headers on a message steer one send and are never passed on:
 * - `X-Relay-Project`: the project to send for, instead of the mailer's or `relay.project`,
 * - `X-Relay-Idempotency-Key`: the `Idempotency-Key` of the call,
 * - `X-Relay-Track-Clicks`: `true` or `false`, click tracking for this mail alone instead
 *   of the sending domain's setting,
 * - `X-Relay-Track-Opens`: the same for open tracking,
 * - `X-Relay-Send-At`: a date and time (ISO 8601) to hold the mail back until.
 *
 * A mailable's own `->tag()` and `->metadata()` become the mail's tags and metadata, and
 * an embedded image stays inline (see {@see EmailMapper}).
 *
 * After the send the message carries `X-Relay-Mail-Id` and `X-Relay-Mail-Status`, so a
 * `MessageSent` listener can keep the id. A mail Relay stored but did not queue
 * (`blocked`, `quarantined`) is not an error here: read the status.
 *
 * When Relay cannot take the mail right now, the transport throws a Symfony
 * `TransportException` with the Relay exception as its previous, so Laravel's `failover`
 * mailer moves on to the next mailer: Relay not configured, unreachable or timing out,
 * a 5xx, a 429 (rate or sending limit), and a 401 or 403 (a problem with the key). When
 * Relay refuses the mail itself (422 `RelayInvalid`, 413 `RelayTooLarge`) the next mailer
 * would send the same bad mail, so that Relay exception is thrown as it is: Symfony's
 * failover only moves on for a `TransportExceptionInterface`, so it stops there and the
 * send fails loudly. Every other Relay error (404, 409, an unreadable answer) is thrown as
 * it is too.
 */
class RelayMailTransport extends AbstractTransport
{
    public const string PROJECT_HEADER = 'X-Relay-Project';

    public const string IDEMPOTENCY_HEADER = 'X-Relay-Idempotency-Key';

    public const string TRACK_CLICKS_HEADER = 'X-Relay-Track-Clicks';

    public const string TRACK_OPENS_HEADER = 'X-Relay-Track-Opens';

    public const string SEND_AT_HEADER = 'X-Relay-Send-At';

    public const string MAIL_ID_HEADER = 'X-Relay-Mail-Id';

    public const string MAIL_STATUS_HEADER = 'X-Relay-Mail-Status';

    /**
     * @param  Closure(): Relay  $relay  resolved per send, so a fake bound later is the one used
     * @param  string|null  $project  the mailer's own project; null for the client's default
     * @param  string|null  $connection  the mailer's own Relay connection (`relay.connections`); null for the default
     */
    public function __construct(
        private readonly Closure $relay,
        private readonly ?string $project = null,
        private readonly ?string $connection = null,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'relay';
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        if (! $original instanceof Message) {
            throw new TransportException('Cbox Relay takes a structured mail, not a raw message: send a Mailable or a Symfony Email.');
        }

        $email = MessageConverter::toEmail($original);

        $project = self::control($email, self::PROJECT_HEADER) ?? $this->project;

        $outgoing = EmailMapper::toOutgoingMail($email, [
            strtolower(self::PROJECT_HEADER),
            strtolower(self::IDEMPOTENCY_HEADER),
            strtolower(self::TRACK_CLICKS_HEADER),
            strtolower(self::TRACK_OPENS_HEADER),
            strtolower(self::SEND_AT_HEADER),
            strtolower(self::MAIL_ID_HEADER),
            strtolower(self::MAIL_STATUS_HEADER),
        ]);
        $clicks = self::flag($email, self::TRACK_CLICKS_HEADER);
        $opens = self::flag($email, self::TRACK_OPENS_HEADER);
        $sendAt = self::moment($email, self::SEND_AT_HEADER);

        $outgoing = $clicks === null ? $outgoing : $outgoing->trackingClicks($clicks);
        $outgoing = $opens === null ? $outgoing : $outgoing->trackingOpens($opens);
        $outgoing = $sendAt === null ? $outgoing : $outgoing->sendingAt($sendAt);

        try {
            $relay = $this->connection === null ? ($this->relay)() : ($this->relay)()->connection($this->connection);
            $mail = ($project === null ? $relay : $relay->forProject($project))->sendMail($outgoing, self::control($email, self::IDEMPOTENCY_HEADER));
        } catch (RelayNotConfigured|RelayUnavailable|RelayRateLimited|RelayUnauthorized|RelayForbidden $e) {
            throw self::cannotTakeIt($e);
        }

        $headers = $original->getHeaders();
        $headers->remove(self::MAIL_ID_HEADER);
        $headers->remove(self::MAIL_STATUS_HEADER);
        $headers->addTextHeader(self::MAIL_ID_HEADER, $mail->id);
        $headers->addTextHeader(self::MAIL_STATUS_HEADER, $mail->status);
    }

    /**
     * Relay could not take the mail now, or not with this key: a transport failure, so a
     * failover mailer tries the next one. The Relay exception stays reachable as the
     * previous, and its message goes into the debug Symfony's failover collects.
     */
    private static function cannotTakeIt(RelayNotConfigured|RelayUnavailable|RelayRateLimited|RelayUnauthorized|RelayForbidden $e): TransportException
    {
        $exception = new TransportException($e->getMessage(), 0, $e);
        $exception->appendDebug($e::class.': '.$e->getMessage());

        return $exception;
    }

    /** A yes-or-no control header: null when it is not there, and a refusal when it says neither. */
    private static function flag(Email $email, string $header): ?bool
    {
        $value = self::control($email, $header);

        return match ($value === null ? null : strtolower($value)) {
            null => null,
            'true', '1', 'yes', 'on' => true,
            'false', '0', 'no', 'off' => false,
            default => throw new TransportException("The header {$header} is true or false, not '{$value}'."),
        };
    }

    /** A moment in a control header: null when it is not there, and a refusal when it is not a date. */
    private static function moment(Email $email, string $header): ?DateTimeImmutable
    {
        $value = self::control($email, $header);

        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            throw new TransportException("The header {$header} is a date and time, not '{$value}'.");
        }
    }

    private static function control(Email $email, string $header): ?string
    {
        $value = trim($email->getHeaders()->get($header)?->getBodyAsString() ?? '');

        return $value === '' ? null : $value;
    }
}
