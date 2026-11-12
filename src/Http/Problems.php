<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Http;

use Cbox\Relay\Client\Data\Problem;
use Cbox\Relay\Client\Exceptions\RelayConflict;
use Cbox\Relay\Client\Exceptions\RelayException;
use Cbox\Relay\Client\Exceptions\RelayForbidden;
use Cbox\Relay\Client\Exceptions\RelayInvalid;
use Cbox\Relay\Client\Exceptions\RelayNotFound;
use Cbox\Relay\Client\Exceptions\RelayRateLimited;
use Cbox\Relay\Client\Exceptions\RelayScopeRequired;
use Cbox\Relay\Client\Exceptions\RelayTooLarge;
use Cbox\Relay\Client\Exceptions\RelayUnauthorized;
use Cbox\Relay\Client\Exceptions\RelayUnavailable;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\Client\Response;

/** What an unsuccessful Relay answer means: its problem document as a typed exception. */
class Problems
{
    public static function exception(Response $response): RelayException
    {
        $status = $response->status();
        $body = $response->json();
        $problem = Problem::fromArray(is_array($body) ? $body : [], $status);
        $message = self::message($problem);

        return match (true) {
            $status === 401 => new RelayUnauthorized($message, $problem),
            $status === 403 => new RelayForbidden($message, $problem),
            $status === 404 => new RelayNotFound($message, $problem),
            $status === 409 => new RelayConflict($message, $problem),
            $status === 413 => new RelayTooLarge($message, $problem),
            // Relay's own word for what the manager refuses before sending: no scope was named.
            $status === 422 && str_ends_with($problem->type, 'scope_required') => new RelayScopeRequired($message, $problem),
            $status === 422 => new RelayInvalid($message, $problem->errors, $problem),
            $status === 429 => new RelayRateLimited($message, self::retryAfter($response), $problem),
            $status >= 500 => new RelayUnavailable($message, $problem),
            default => new RelayException($message, $problem),
        };
    }

    /** Status, title and detail. Only what Relay said: never the key, never the request. */
    private static function message(Problem $problem): string
    {
        $summary = trim($problem->status.' '.$problem->title);

        return 'Cbox Relay answered '.$summary.($problem->detail === null ? '.' : ': '.$problem->detail);
    }

    /** `Retry-After` in seconds, whether Relay sent seconds or an HTTP date. */
    private static function retryAfter(Response $response): ?int
    {
        $header = trim($response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        try {
            return max((new DateTimeImmutable($header))->getTimestamp() - time(), 0);
        } catch (Exception) {
            return null;
        }
    }
}
