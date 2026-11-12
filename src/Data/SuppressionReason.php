<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/** Why an address is on the suppression list. */
enum SuppressionReason: string
{
    case Bounce = 'bounce';
    case Complaint = 'complaint';
    case Unsubscribe = 'unsubscribe';
    case Manual = 'manual';
}
