<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Support;

enum DoctorStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Failure = 'failure';
}
