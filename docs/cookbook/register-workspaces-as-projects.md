---
title: Register workspaces as projects
description: Keep Relay's projects in step with your own workspaces with an idempotent put from a queued listener.
weight: 31
---

# Register workspaces as projects

Put the project when a workspace is created or renamed. Because `put` is idempotent, the
same job covers both, and running it twice is harmless.

```php
namespace App\Jobs;

use App\Models\Workspace;
use Cbox\Relay\Client\Exceptions\RelayRateLimited;
use Cbox\Relay\Client\Exceptions\RelayUnavailable;
use Cbox\Relay\Client\Relay;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RegisterRelayProject implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public Workspace $workspace) {}

    public function handle(Relay $relay): void
    {
        try {
            $relay->projects()->put((string) $this->workspace->id, name: $this->workspace->name);
        } catch (RelayRateLimited $e) {
            $this->release($e->retryAfter ?? 30);
        } catch (RelayUnavailable) {
            $this->release(60);
        }
    }
}
```

Any other `RelayException` (a bad key, a validation error) fails the job: repeating it
would not help.

## Backfill

```php
Workspace::query()->each(fn (Workspace $workspace) => RegisterRelayProject::dispatch($workspace));
```

## Test it

```php
use Cbox\Relay\Client\Facades\Relay;
use Cbox\Relay\Client\Testing\RecordedCall;

it('registers a new workspace with Relay', function (): void {
    $relay = Relay::fake();

    $workspace = Workspace::factory()->create(['name' => 'Acme']);

    $relay->assertProjectPut((string) $workspace->id, fn (RecordedCall $call): bool => $call->name === 'Acme');
    expect($relay->project((string) $workspace->id)?->name)->toBe('Acme');
});
```
