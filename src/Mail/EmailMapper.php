<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Mail;

use Cbox\Relay\Client\Data\MailAddress;
use Cbox\Relay\Client\Data\MailAttachment;
use Cbox\Relay\Client\Data\OutgoingMail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Header\UnstructuredHeader;

/**
 * Turns a Symfony `Email` into the structured mail Relay's API takes.
 *
 * What the API has no place for is left behind, not sent:
 * - display names of recipients (the API takes plain addresses),
 * - every reply-to address after the first,
 * - headers Relay does not let a sender set ({@see OutgoingMail::allowsHeader()}),
 * - the inline disposition of an embedded image: it goes as an ordinary attachment.
 */
class EmailMapper
{
    /**
     * @param  list<string>  $withoutHeaders  header names to leave out besides the ones Relay refuses, in lower case
     */
    public static function toOutgoingMail(Email $email, array $withoutHeaders = []): OutgoingMail
    {
        $from = $email->getFrom()[0] ?? throw new TransportException('A mail sent through Cbox Relay needs a From address.');
        $replyTo = $email->getReplyTo()[0] ?? null;

        return new OutgoingMail(
            from: new MailAddress($from->getAddress(), $from->getName() === '' ? null : $from->getName()),
            to: self::addresses($email->getTo()),
            subject: $email->getSubject() ?? '',
            html: self::content($email->getHtmlBody()),
            text: self::content($email->getTextBody()),
            cc: self::addresses($email->getCc()),
            bcc: self::addresses($email->getBcc()),
            replyTo: $replyTo?->getAddress(),
            headers: self::headers($email, $withoutHeaders),
            attachments: self::attachments($email),
        );
    }

    /**
     * @param  array<array-key, Address>  $addresses
     * @return list<string>
     */
    private static function addresses(array $addresses): array
    {
        return array_values(array_map(static fn (Address $address): string => $address->getAddress(), $addresses));
    }

    /**
     * @param  resource|string|null  $body
     */
    private static function content(mixed $body): ?string
    {
        if ($body === null || is_string($body)) {
            return $body;
        }

        $read = stream_get_contents($body);

        return $read === false ? null : $read;
    }

    /**
     * The headers Relay lets a sender set, each on one line.
     *
     * @param  list<string>  $without
     * @return array<string, string>
     */
    private static function headers(Email $email, array $without): array
    {
        $headers = [];

        foreach ($email->getHeaders()->all() as $header) {
            if (! $header instanceof HeaderInterface) {
                continue;
            }

            $name = $header->getName();

            if (! OutgoingMail::allowsHeader($name) || in_array(strtolower($name), $without, true)) {
                continue;
            }

            $headers[$name] = self::value($header);
        }

        return $headers;
    }

    private static function value(HeaderInterface $header): string
    {
        $value = $header instanceof UnstructuredHeader ? $header->getValue() : $header->getBodyAsString();

        // Unfolded: Relay takes a header value as one line.
        return trim(preg_replace('/\r?\n[ \t]*/', ' ', $value) ?? $value);
    }

    /**
     * @return list<MailAttachment>
     */
    private static function attachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $part) {
            $attachments[] = new MailAttachment(
                $part->getFilename() ?? 'attachment',
                $part->getMediaType().'/'.$part->getMediaSubtype(),
                $part->getBody(),
            );
        }

        return $attachments;
    }
}
