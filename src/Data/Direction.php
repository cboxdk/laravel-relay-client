<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/** Whether a judged mail is one that came in or one that goes out. */
enum Direction: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
