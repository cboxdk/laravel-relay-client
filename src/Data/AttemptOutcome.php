<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/** How one attempt to reach a webhook endpoint ended, as Relay names it today. */
enum AttemptOutcome: string
{
    case Success = 'success';
    case HttpError = 'http_error';
    case Timeout = 'timeout';
    case ConnectionError = 'connection_error';
    case BlockedUrl = 'blocked_url';
    case BreakerOpen = 'breaker_open';
    case RateLimited = 'rate_limited';
    case Cancelled = 'cancelled';
    case Deferred = 'deferred';
}
