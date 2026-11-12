<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Mail;

use Cbox\Relay\Client\Relay;
use Closure;
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
 * Two headers on a message steer one send and are never passed on:
 * - `X-Relay-Project`: the project to send for, instead of the mailer's or `relay.project`,
 * - `X-Relay-Idempotency-Key`: the `Idempotency-Key` of the call.
 *
 * After the send the message carries `X-Relay-Mail-Id` and `X-Relay-Mail-Status`, so a
 * `MessageSent` listener can keep the id. A mail Relay stored but did not queue
 * (`blocked`, `quarantined`) is not an error here: read the status.
 */
class RelayMailTransport extends AbstractTransport
{
    public const string PROJECT_HEADER = 'X-Relay-Project';

    public const string IDEMPOTENCY_HEADER = 'X-Relay-Idempotency-Key';

    public const string MAIL_ID_HEADER = 'X-Relay-Mail-Id';

    public const string MAIL_STATUS_HEADER = 'X-Relay-Mail-Status';

    /**
     * @param  Closure(): Relay  $relay  resolved per send, so a fake bound later is the one used
     * @param  string|null  $project  the mailer's own project; null for the client's default
     */
    public function __construct(
        private readonly Closure $relay,
        private readonly ?string $project = null,
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
        $relay = ($this->relay)();

        $mail = ($project === null ? $relay : $relay->forProject($project))->sendMail(
            EmailMapper::toOutgoingMail($email, [
                strtolower(self::PROJECT_HEADER),
                strtolower(self::IDEMPOTENCY_HEADER),
                strtolower(self::MAIL_ID_HEADER),
                strtolower(self::MAIL_STATUS_HEADER),
            ]),
            self::control($email, self::IDEMPOTENCY_HEADER),
        );

        $headers = $original->getHeaders();
        $headers->remove(self::MAIL_ID_HEADER);
        $headers->remove(self::MAIL_STATUS_HEADER);
        $headers->addTextHeader(self::MAIL_ID_HEADER, $mail->id);
        $headers->addTextHeader(self::MAIL_STATUS_HEADER, $mail->status);
    }

    private static function control(Email $email, string $header): ?string
    {
        $value = trim($email->getHeaders()->get($header)?->getBodyAsString() ?? '');

        return $value === '' ? null : $value;
    }
}
