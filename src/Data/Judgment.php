<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;

/**
 * The judge's answer: a score from 0 to 1 per verdict, a category and the findings
 * behind the scores. The scores come from fixed rules; they order content, they are not
 * measured probabilities.
 *
 * `$created` is true when this call made the judgment (Relay answered 201) and false
 * when exactly this content had been judged before, and on every later read.
 */
readonly class Judgment
{
    /**
     * @param  array<string, float>  $verdicts  score per verdict name
     * @param  list<Finding>  $findings
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $direction,
        public string $judgedBy,
        public string $category,
        public DateTimeImmutable $createdAt,
        public array $verdicts = [],
        public array $findings = [],
        public ?TemplateReference $template = null,
        public ?string $brand = null,
        public bool $created = false,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, bool $created = false): self
    {
        $template = Payload::nullableMap($data, 'template');

        return new self(
            id: Payload::string($data, 'id'),
            kind: Payload::string($data, 'kind'),
            direction: Payload::string($data, 'direction'),
            judgedBy: Payload::string($data, 'judged_by'),
            category: Payload::string($data, 'category'),
            createdAt: Payload::date($data, 'created_at'),
            verdicts: Payload::numberMap($data, 'verdicts'),
            findings: array_map(Finding::fromArray(...), Payload::maps($data, 'findings')),
            template: $template === null ? null : TemplateReference::fromArray($template),
            brand: Payload::nullableString($data, 'brand'),
            created: $created,
        );
    }

    /** The score for one verdict (`phishing`, `link_mismatch`, …); 0.0 when Relay gave none. */
    public function score(string $verdict): float
    {
        return $this->verdicts[$verdict] ?? 0.0;
    }

    /** The highest score of any verdict. */
    public function highest(): float
    {
        return $this->verdicts === [] ? 0.0 : max($this->verdicts);
    }
}
