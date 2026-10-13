<?php

namespace Themosis\Core\Console;

use Illuminate\Console\Command;

class OptimizeClearCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'optimize:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove the cached bootstrap files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $result = 0;

        // Keep clearing after a failure so one broken store does not leave other caches stale.
        foreach (['view:clear', 'cache:clear', 'route:clear', 'event:clear', 'config:clear', 'clear-compiled'] as $command) {
            if (($exitCode = $this->call($command)) !== 0) {
                $this->error("Command [{$command}] failed.");
                $result = $result ?: $exitCode;
            }
        }

        if ($result !== 0) {
            return $result;
        }

        $this->info('Caches cleared successfully!');
    }
}
