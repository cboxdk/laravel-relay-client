<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

/**
 * The tags and metadata a mail was sent with, as every event about a sent mail carries
 * them. Read leniently: an event from before Relay sent them has neither.
 */
final class Labels
{
    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public static function tags(array $data): array
    {
        $tags = $data['tags'] ?? null;

        return is_array($tags) ? array_values(array_filter($tags, is_string(...))) : [];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, string>
     */
    public static function metadata(array $data): array
    {
        $metadata = [];

        foreach (is_array($data['metadata'] ?? null) ? $data['metadata'] : [] as $key => $value) {
            if (is_string($value)) {
                $metadata[(string) $key] = $value;
            }
        }

        return $metadata;
    }
}
