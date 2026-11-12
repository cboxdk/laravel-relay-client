<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * An RFC 9457 problem document, as Relay answers every error with
 * (`application/problem+json`). `$errors` is only filled for validation problems.
 * `$extensions` holds the members a problem adds of its own, such as `scope` on
 * `sending_limited` and `mail_id` on `idempotency_key_reused`.
 */
readonly class Problem
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public int $status = 0,
        public string $title = '',
        public ?string $detail = null,
        public string $type = 'about:blank',
        public array $errors = [],
        public array $extensions = [],
    ) {}

    /** One of the problem's own members as text; null when it is not there or not text. */
    public function extension(string $name): ?string
    {
        $value = $this->extensions[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

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
            extensions: array_filter(
                array_diff_key($data, array_flip(['type', 'title', 'status', 'detail', 'errors', 'instance'])),
                is_string(...),
                ARRAY_FILTER_USE_KEY,
            ),
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
            ...$this->extensions,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
