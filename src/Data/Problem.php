<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * An RFC 9457 problem document, as Relay answers every error with
 * (`application/problem+json`). `$errors` is only filled for validation problems.
 */
readonly class Problem
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        public int $status = 0,
        public string $title = '',
        public ?string $detail = null,
        public string $type = 'about:blank',
        public array $errors = [],
    ) {}

    /**
     * Lenient on purpose: an error answer that is not a problem document (a proxy's HTML
     * page, an empty body) still becomes a Problem carrying the status.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, int $status): self
    {
        $title = $data['title'] ?? null;
        $detail = $data['detail'] ?? null;
        $type = $data['type'] ?? null;
        $fields = $data['errors'] ?? null;
        $errors = [];

        foreach (is_array($fields) ? $fields : [] as $field => $messages) {
            $errors[(string) $field] = array_values(array_filter(is_array($messages) ? $messages : [$messages], 'is_string'));
        }

        return new self(
            status: $status,
            title: is_string($title) ? $title : '',
            detail: is_string($detail) && $detail !== '' ? $detail : null,
            type: is_string($type) && $type !== '' ? $type : 'about:blank',
            errors: $errors,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
            'errors' => $this->errors === [] ? null : $this->errors,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
