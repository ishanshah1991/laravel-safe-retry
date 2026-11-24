<?php

namespace IshanShah\SafeRetry\Console;

use Illuminate\Console\Command;
use IshanShah\SafeRetry\SafeRetryManager;
use IshanShah\SafeRetry\Stores\DatabaseStore;

class PruneCommand extends Command
{
    protected $signature = 'safe-retry:prune {--store= : The store to prune (defaults to the configured store)}';

    protected $description = 'Delete expired idempotency records and stale locks from the database store';

    public function handle(SafeRetryManager $manager): int
    {
        $store = $manager->store($this->option('store'));

        if (! $store instanceof DatabaseStore) {
            $this->components->info('Nothing to prune: only the database store needs pruning. Cache and Redis entries expire on their own.');

            return self::SUCCESS;
        }

        $this->components->info("Pruned {$store->prune()} expired safe-retry rows.");

        return self::SUCCESS;
    }
}
