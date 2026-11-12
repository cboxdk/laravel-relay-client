---
title: The judge
description: Score a mail or a template without sending it, and read the verdict scores, the category and the findings.
weight: 28
---

# The judge

```php
use Cbox\Relay\Client\Data\TemplateReference;

$judgment = Relay::forProject($ref)->judge(
    html: $html,
    subject: 'Welcome',
    from: 'hello@acme.dk',                           // or a MailAddress with a name
    template: new TemplateReference('welcome', '3'),
);

$judgment->verdicts;                 // ['phishing' => 0.0, 'link_mismatch' => 0.6, …]
$judgment->score('link_mismatch');   // 0.6; 0.0 for a verdict Relay did not score
$judgment->highest();                // the highest score of any verdict
$judgment->category;                 // 'transactional', 'marketing', 'personal' or 'other'
$judgment->brand;                    // the brand a brand_impersonation score is about
$judgment->judgedBy;                 // 'rules'
$judgment->created;                  // false when exactly this content was judged before

foreach ($judgment->findings as $finding) {
    $finding->verdict; $finding->layer; $finding->rule; $finding->score; $finding->detail;
}

Relay::forProject($ref)->judgment($judgment->id);   // ?Judgment
```

At least one of `html` and `text` is needed. Further arguments: `kind` (`JudgeKind::Mail`
or `Template`), `direction` (`Direction::Inbound` or `Outbound`), `headers` and
`idempotencyKey`.

Nothing is sent and nothing is blocked: the judge returns numbers, and what to do with
them is yours to decide. Relay stores the scores, the findings and a hash of the
content, not the content.

## What the scores are

They come from fixed rules in the service, so the same content gets the same answer.
They are weights, not measured probabilities: treat them as an order, not a likelihood.
That is Relay's own statement about its judge; this package only carries the numbers.

Needs `judge:run`.
