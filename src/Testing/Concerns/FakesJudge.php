<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing\Concerns;

use Cbox\Relay\Client\Data\Finding;
use Cbox\Relay\Client\Data\JudgeInput;
use Cbox\Relay\Client\Data\Judgment;
use Cbox\Relay\Client\Testing\Operation;
use Cbox\Relay\Client\Testing\RecordedCall;
use PHPUnit\Framework\Assert;

/** The judge, in memory: nothing is scored, the test says what a judgment answers. */
trait FakesJudge
{
    /** The verdicts Relay scores today. */
    private const array VERDICTS = ['phishing', 'brand_impersonation', 'link_mismatch', 'credential_request', 'auto_reply', 'unsubscribe_intent'];

    /** @var array<string, array<string, Judgment>> by scope, then id */
    private array $judgments = [];

    /** @var array<string, string> content fingerprint => judgment id */
    private array $judgedContent = [];

    /** @var list<array{input: JudgeInput, scope: string|null}> */
    private array $judged = [];

    /** @var array<string, float> */
    private array $nextVerdicts = [];

    private string $nextCategory = 'other';

    /** @var list<Finding> */
    private array $nextFindings = [];

    private ?string $nextBrand = null;

    public function judge(JudgeInput $input, ?string $idempotencyKey = null, ?string $scope = null): Judgment
    {
        $this->record(new RecordedCall(Operation::Judge, $scope, idempotencyKey: $idempotencyKey, subject: $input));
        $bucket = $this->bucket($scope);

        if (($input->html === null || $input->html === '') && ($input->text === null || $input->text === '')) {
            throw $this->invalid('html', 'At least one of html and text is required.');
        }

        $this->judged[] = ['input' => $input, 'scope' => $scope];

        // Exactly the same content again is the judgment it got before.
        $content = $this->fingerprint(Operation::Judge, $bucket, $input);
        $known = $this->judgments[$bucket][$this->judgedContent[$content] ?? ''] ?? null;

        if ($known !== null) {
            return $known;
        }

        $judgment = new Judgment(
            id: 'jdg_fake_'.$this->nextNumber(),
            kind: $input->kind->value ?? ($input->template === null ? 'mail' : 'template'),
            direction: $input->direction->value ?? 'outbound',
            judgedBy: 'rules',
            category: $this->nextCategory,
            createdAt: $this->now(),
            verdicts: [...array_fill_keys(self::VERDICTS, 0.0), ...$this->nextVerdicts],
            findings: $this->nextFindings,
            template: $input->template,
            brand: $this->nextBrand,
        );

        $this->judgments[$bucket][$judgment->id] = $judgment;
        $this->judgedContent[$content] = $judgment->id;

        // Only this answer says "created"; what is stored reads back like any other judgment.
        return new Judgment(
            $judgment->id, $judgment->kind, $judgment->direction, $judgment->judgedBy, $judgment->category, $judgment->createdAt,
            $judgment->verdicts, $judgment->findings, $judgment->template, $judgment->brand, true,
        );
    }

    public function findJudgment(string $id, ?string $scope = null): ?Judgment
    {
        $this->record(new RecordedCall(Operation::FindJudgment, $scope, $id));

        return $this->judgments[$this->bucket($scope)][$id] ?? null;
    }

    /**
     * What new judgments answer from now on. Verdicts left out score 0.
     *
     * @param  array<string, float>  $verdicts  score per verdict name, 0 to 1
     * @param  list<Finding>  $findings
     */
    public function judgeAs(array $verdicts = [], string $category = 'other', array $findings = [], ?string $brand = null): static
    {
        $this->nextVerdicts = $verdicts;
        $this->nextCategory = $category;
        $this->nextFindings = $findings;
        $this->nextBrand = $brand;

        return $this;
    }

    /**
     * Something was sent to the judge (and matches `$where`).
     *
     * @param  (callable(JudgeInput, string|null): bool)|null  $where  given the input and the scope it was judged for
     */
    public function assertJudged(?callable $where = null): void
    {
        $matching = array_filter($this->judged, static fn (array $judged): bool => $where === null || $where($judged['input'], $judged['scope']));

        Assert::assertNotEmpty($matching, $where === null
            ? 'Nothing was sent to the Cbox Relay judge.'
            : 'Nothing matching the condition was sent to the Cbox Relay judge.');
    }

    public function assertNothingJudged(): void
    {
        Assert::assertCount(0, $this->judged, 'Content was sent to the Cbox Relay judge.');
    }
}
