<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing;

/** The calls {@see FakeRelay} records, one per method of the transport contract. */
enum Operation: string
{
    case Whoami = 'whoami';
    case PutScope = 'put_scope';
    case FindScope = 'find_scope';
    case ListScopes = 'list_scopes';
    case ListDeliveries = 'list_deliveries';
}
