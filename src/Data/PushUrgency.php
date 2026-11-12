<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/** How soon a push service wakes the device for a message. */
enum PushUrgency: string
{
    case VeryLow = 'very-low';
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
}
