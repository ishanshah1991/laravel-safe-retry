<?php

namespace IshanShah\SafeRetry\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'safe-retry:install
        {--database : Also publish the migration for the database store}
        {--force : Overwrite existing files}';

    protected $description = 'Publish the safe-retry config (and optionally the database migration)';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'safe-retry-config', '--force' => $this->option('force')]);

        if ($this->option('database')) {
            $this->call('vendor:publish', ['--tag' => 'safe-retry-migrations', '--force' => $this->option('force')]);
            $this->components->info('Run "php artisan migrate", then set SAFE_RETRY_STORE=database.');
            $this->components->info('Schedule "safe-retry:prune" to delete expired rows.');
        }

        $this->components->info('safe-retry installed. Add ->middleware(\'safe-retry\') to routes with side effects.');

        return self::SUCCESS;
    }
}
