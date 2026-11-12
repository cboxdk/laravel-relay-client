<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use Cbox\Relay\Client\Exceptions\RelayMalformedResponse;
use DateTimeImmutable;
use Exception;

/**
 * Reads typed values out of a decoded JSON body. This is the one place the loose array
 * Relay's HTTP answers arrive as is turned into something typed; a value that is missing
 * or of the wrong type is a {@see RelayMalformedResponse}, never a silent default.
 */
class Payload
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function string(array $data, string $key): string
    {
        return self::nullableString($data, $key) ?? throw self::malformed($key, 'a string');
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null || is_string($value)) {
            return $value;
        }

        throw self::malformed($key, 'a string or null');
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function int(array $data, string $key): int
    {
        return self::nullableInt($data, $key) ?? throw self::malformed($key, 'an integer');
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if ($value === null || is_int($value)) {
            return $value;
        }

        throw self::malformed($key, 'an integer or null');
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function date(array $data, string $key): DateTimeImmutable
    {
        return self::nullableDate($data, $key) ?? throw self::malformed($key, 'an ISO 8601 date');
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function nullableDate(array $data, string $key): ?DateTimeImmutable
    {
        $value = self::nullableString($data, $key);

        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception $e) {
            throw self::malformed($key, 'an ISO 8601 date', $e);
        }
    }

    /**
     * A JSON object. An empty one decodes to an empty array, which is accepted.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    public static function map(array $data, string $key): array
    {
        return self::nullableMap($data, $key) ?? throw self::malformed($key, 'an object');
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>|null
     */
    public static function nullableMap(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw self::malformed($key, 'an object or null');
        }

        $map = [];

        foreach ($value as $name => $item) {
            $map[(string) $name] = $item;
        }

        return $map;
    }

    /**
     * A JSON array of objects.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<array<array-key, mixed>>
     */
    public static function maps(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw self::malformed($key, 'an array');
        }

        $maps = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                throw self::malformed($key, 'an array of objects');
            }

            $maps[] = $item;
        }

        return $maps;
    }

    /**
     * A JSON array of strings.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw self::malformed($key, 'an array of strings');
        }

        $strings = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw self::malformed($key, 'an array of strings');
            }

            $strings[] = $item;
        }

        return $strings;
    }

    private static function malformed(string $key, string $expected, ?Exception $previous = null): RelayMalformedResponse
    {
        return new RelayMalformedResponse("Cbox Relay answered with a body this client cannot read: '{$key}' should be {$expected}.", null, $previous);
    }
}
