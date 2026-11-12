---
title: Page through the delivery log
description: Show one workspace's delivery log with next-page links, using the cursor Relay hands back.
weight: 32
---

# Page through the delivery log

The log is cursor-paginated: each page carries the cursor for the next one. Put the
cursor in the query string of your own "next" link.

```php
use Cbox\Relay\Client\Data\DeliveryFilter;
use Cbox\Relay\Client\Relay;
use Illuminate\Http\Request;

public function index(Request $request, Workspace $workspace, Relay $relay)
{
    $this->authorize('view', $workspace);   // the scope below comes from the route

    $page = $relay->forScope((string) $workspace->id)->deliveries(
        new DeliveryFilter(status: $request->string('status')->toString() ?: null),
        limit: 25,
        cursor: $request->string('cursor')->toString() ?: null,
    );

    return view('deliveries.index', [
        'deliveries' => $page->items,
        'next' => $page->nextCursor,
    ]);
}
```

There is no "previous" cursor and no total: keep the cursors you have seen if you need
to go back.

The fake's log holds what you seed; nothing it records adds a line by itself. Build the
screen against it:

```php
$relay = Relay::fake();
$relay->seedDeliveryFor('7', status: 'failed', responseCode: 500);
$relay->seedDeliveryFor('7', status: 'delivered', responseCode: 200);

$this->actingAs($member)->get('/workspaces/7/deliveries?status=failed')
    ->assertSee('500')
    ->assertDontSee('200');
```

## Reading everything

For an export or a job, loop until the cursor runs out:

```php
$cursor = null;

do {
    $page = $relay->deliveries(new DeliveryFilter(from: now()->subDay()), limit: 100, cursor: $cursor);

    foreach ($page as $delivery) {
        // …
    }

    $cursor = $page->nextCursor;
} while ($cursor !== null);
```

One iteration is one request; a `RelayRateLimited` in the middle tells you how long to
wait before continuing from the same cursor.
