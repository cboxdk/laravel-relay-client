<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Console;

use Cbox\Relay\Client\Data\Delivery;
use Cbox\Relay\Client\Data\DeliveryFilter;
use Cbox\Relay\Client\Exceptions\RelayException;
use Cbox\Relay\Client\Relay;
use Illuminate\Console\Command;

class TailCommand extends Command
{
    protected $signature = 'relay:tail
        {--limit=20 : How many attempts to show, newest first (1 to 100)}
        {--channel= : Only this channel: webhook, mail, push, …}
        {--status= : Only this status: delivered, failed, …}
        {--project= : Act for this project instead of the default}
        {--connection= : A connection from relay.connections}';

    protected $description = 'Show the newest lines of the delivery log in Cbox Relay';

    public function handle(Relay $relay): int
    {
        $connection = $this->option('connection');
        $project = $this->option('project');
        $channel = $this->option('channel');
        $status = $this->option('status');
        $limit = $this->option('limit');
        $limit = is_numeric($limit) ? min(100, max(1, (int) $limit)) : 20;

        try {
            $client = is_string($connection) && $connection !== '' ? $relay->connection($connection) : $relay;
            $client = is_string($project) && $project !== '' ? $client->forProject($project) : $client;
            $page = $client->deliveries(new DeliveryFilter(
                is_string($channel) && $channel !== '' ? $channel : null,
                is_string($status) && $status !== '' ? $status : null,
            ), $limit);
        } catch (RelayException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($page->items === []) {
            $this->components->info('Nothing in the delivery log matches.');

            return self::SUCCESS;
        }

        $this->table(['When (UTC)', 'Channel', 'Status', 'Target', 'Try', 'Code', 'Error'], array_map(static fn (Delivery $delivery): array => [
            $delivery->occurredAt->format('Y-m-d H:i:s'),
            $delivery->channel,
            $delivery->status,
            mb_strimwidth($delivery->target, 0, 48, '…'),
            (string) $delivery->attempt,
            $delivery->responseCode === null ? '' : (string) $delivery->responseCode,
            mb_strimwidth($delivery->error ?? '', 0, 60, '…'),
        ], $page->items));

        return self::SUCCESS;
    }
}
