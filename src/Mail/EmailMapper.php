<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Mail;

use Cbox\Relay\Client\Data\MailAddress;
use Cbox\Relay\Client\Data\MailAttachment;
use Cbox\Relay\Client\Data\OutgoingMail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
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
 * - headers Relay does not let a sender set ({@see OutgoingMail::allowsHeader()}).
 *
 * What Laravel and Symfony have their own way of saying is carried over:
 * - `->tag()` on a mailable (Symfony's `TagHeader`) becomes one of the mail's tags,
 * - `->metadata()` (Symfony's `MetadataHeader`) becomes its metadata,
 * - an embedded image (`$message->embed()`, an inline attachment) goes out inline, with a
 *   content id Relay accepts, and the `cid:` reference in the HTML follows it.
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
        $html = self::content($email->getHtmlBody());
        $attachments = self::attachments($email, $html);

        return new OutgoingMail(
            from: new MailAddress($from->getAddress(), $from->getName() === '' ? null : $from->getName()),
            to: self::addresses($email->getTo()),
            subject: $email->getSubject() ?? '',
            html: $html,
            text: self::content($email->getTextBody()),
            cc: self::addresses($email->getCc()),
            bcc: self::addresses($email->getBcc()),
            replyTo: $replyTo?->getAddress(),
            headers: self::headers($email, $withoutHeaders),
            attachments: $attachments,
            tags: self::tags($email),
            metadata: self::metadata($email),
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

            // A tag and metadata travel as the mail's own, not as headers.
            if ($header instanceof TagHeader || $header instanceof MetadataHeader) {
                continue;
            }

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
     * @return list<string>
     */
    private static function tags(Email $email): array
    {
        $tags = [];

        foreach ($email->getHeaders()->all() as $header) {
            if ($header instanceof TagHeader && $header->getValue() !== '') {
                $tags[] = $header->getValue();
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * @return array<string, string>
     */
    private static function metadata(Email $email): array
    {
        $metadata = [];

        foreach ($email->getHeaders()->all() as $header) {
            if ($header instanceof MetadataHeader) {
                $metadata[$header->getKey()] = $header->getValue();
            }
        }

        return $metadata;
    }

    /**
     * The files of a mail. An inline one that the HTML refers to keeps its place: it gets a
     * content id Relay takes (letters, digits, `.`, `_`, `-`), and `$html` is changed to
     * refer to it by that id. An inline file nothing refers to is an ordinary attachment.
     *
     * @return list<MailAttachment>
     */
    private static function attachments(Email $email, ?string &$html): array
    {
        $attachments = [];
        $used = [];

        foreach ($email->getAttachments() as $number => $part) {
            $name = $part->getFilename() ?? $part->getName() ?? 'attachment';
            $contentId = null;

            if ($html !== null && $part->getDisposition() === 'inline') {
                // Laravel's embed() writes `cid:` and the part's name; Symfony's own embedding writes its content id.
                $references = array_values(array_filter([
                    $part->getName() === null ? null : 'cid:'.$part->getName(),
                    $part->hasContentId() ? 'cid:'.$part->getContentId() : null,
                ], static fn (?string $reference): bool => $reference !== null && str_contains($html, $reference)));

                if ($references !== []) {
                    $contentId = self::contentId($part->getName() ?? $name, $number, $used);
                    $html = str_replace($references, 'cid:'.$contentId, $html);
                }
            }

            $attachments[] = new MailAttachment($name, $part->getMediaType().'/'.$part->getMediaSubtype(), $part->getBody(), $contentId);
        }

        return $attachments;
    }

    /**
     * A content id of the shape Relay takes, unique in the mail.
     *
     * @param  list<string>  $used
     */
    private static function contentId(string $wanted, int $number, array &$used): string
    {
        $id = substr(trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $wanted), '-'), 0, 90);
        $id = $id === '' ? 'inline' : $id;

        if (in_array($id, $used, true)) {
            $id .= '-'.$number;
        }

        return $used[] = $id;
    }
}
