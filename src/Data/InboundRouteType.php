<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/** What an inbound route claims: a domain of your own, a name under the shared domain, a prefix on the shared domain, or one address. */
enum InboundRouteType: string
{
    case Domain = 'domain';
    case Subdomain = 'subdomain';
    case Prefix = 'prefix';
    case Address = 'address';
}
