<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/** What the judge is asked to score. */
enum JudgeKind: string
{
    case Mail = 'mail';
    case Template = 'template';
}
