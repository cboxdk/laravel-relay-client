<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks;

use Cbox\Relay\Client\Exceptions\RelayWebhookRejected;

/**
 * The `cbox` signature scheme Relay signs its webhooks with:
 * `X-Cbox-Signature: t=<unix>,v1=<hex>`, where the hex is HMAC-SHA256 over
 * `"{t}.{body}"` with the endpoint's secret as the key, exactly as written.
 *
 * The MAC is PHP's `hash_hmac()` and the comparison `hash_equals()`; nothing here is a
 * cryptographic algorithm of its own. The timestamp that counts is the `t=` inside the
 * signature header, because that one is signed; the separate `X-Cbox-Timestamp` header
 * is not, and is never read.
 *
 * Endpoints registered with the `standard-webhooks` scheme are not covered: use a
 * Standard Webhooks library for those.
 */
class WebhookSignature
{
    public const string HEADER = 'X-Cbox-Signature';

    public const int DEFAULT_TOLERANCE = 300;

    /**
     * Check a delivery. Returns the signed timestamp when it holds.
     *
     * @param  string  $body  the raw request body, byte for byte as received
     * @param  list<string>  $secrets  the current secret and, during a rotation, the previous one
     * @param  int  $tolerance  seconds the timestamp may be off, either way
     *
     * @throws RelayWebhookRejected
     */
    public static function verify(string $body, ?string $header, array $secrets, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): int
    {
        $secrets = array_values(array_filter($secrets, static fn (string $secret): bool => $secret !== ''));

        // No secret refuses everything: an unset variable must not open the endpoint.
        if ($secrets === []) {
            throw new RelayWebhookRejected(RelayWebhookRejected::REASON_NO_SECRET);
        }

        if ($header === null || trim($header) === '') {
            throw new RelayWebhookRejected(RelayWebhookRejected::REASON_MISSING_SIGNATURE);
        }

        [$rawTimestamp, $presented] = self::parse($header);

        if ($presented === null) {
            throw new RelayWebhookRejected(RelayWebhookRejected::REASON_MALFORMED_SIGNATURE);
        }

        if ($rawTimestamp === null || $rawTimestamp === '') {
            throw new RelayWebhookRejected(RelayWebhookRejected::REASON_MISSING_TIMESTAMP);
        }

        // Not is_numeric: that takes "1e9" and other forms that cast to something surprising.
        if (! ctype_digit($rawTimestamp)) {
            throw new RelayWebhookRejected(RelayWebhookRejected::REASON_MALFORMED_TIMESTAMP);
        }

        $timestamp = (int) $rawTimestamp;
        $now ??= time();

        if ($timestamp < $now - $tolerance) {
            throw new RelayWebhookRejected(RelayWebhookRejected::REASON_STALE_TIMESTAMP);
        }

        // Two-sided: a forward-dated delivery would otherwise stay valid for as long as it was post-dated.
        if ($timestamp > $now + $tolerance) {
            throw new RelayWebhookRejected(RelayWebhookRejected::REASON_FUTURE_TIMESTAMP);
        }

        $matched = false;

        // Every secret is tried, match or not, so the work does not tell which one verified.
        foreach ($secrets as $secret) {
            if (hash_equals(self::compute($body, $secret, $timestamp), strtolower($presented))) {
                $matched = true;
            }
        }

        if (! $matched) {
            throw new RelayWebhookRejected(RelayWebhookRejected::REASON_SIGNATURE_MISMATCH);
        }

        return $timestamp;
    }

    /**
     * The header value Relay would send for this body: for your own tests of a route
     * behind the `relay.webhook` middleware.
     */
    public static function header(string $body, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return sprintf('t=%d,v1=%s', $timestamp, self::compute($body, $secret, $timestamp));
    }

    private static function compute(string $body, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * The first `t=` and the first well-formed `v1=` of the header.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function parse(string $header): array
    {
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't' && $timestamp === null) {
                $timestamp = trim($value);
            } elseif ($key === 'v1' && $signature === null && strlen($value) === 64 && ctype_xdigit($value)) {
                $signature = $value;
            }
        }

        return [$timestamp, $signature];
    }
}
