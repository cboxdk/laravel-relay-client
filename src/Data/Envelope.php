<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/** The body a webhook endpoint receives: the Cbox envelope, or CloudEvents 1.0 structured JSON. */
enum Envelope: string
{
    case Cbox = 'cbox';
    case CloudEvents = 'cloudevents';
}
