<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Support;

/** One line of `relay:doctor`: what was looked at, how it stands, and what to do about it. */
readonly class DoctorCheck
{
    public function __construct(
        public string $section,
        public string $name,
        public DoctorStatus $status,
        public string $message = '',
    ) {}

    public static function ok(string $section, string $name, string $message = ''): self
    {
        return new self($section, $name, DoctorStatus::Ok, $message);
    }

    public static function warning(string $section, string $name, string $message): self
    {
        return new self($section, $name, DoctorStatus::Warning, $message);
    }

    public static function failure(string $section, string $name, string $message): self
    {
        return new self($section, $name, DoctorStatus::Failure, $message);
    }
}
