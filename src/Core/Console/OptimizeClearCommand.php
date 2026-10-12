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
        foreach (['view:clear', 'cache:clear', 'route:clear', 'event:clear', 'config:clear', 'clear-compiled'] as $command) {
            if (($exitCode = $this->call($command)) !== 0) {
                $this->error("Command [{$command}] failed.");

                return $exitCode;
            }
        }

        $this->info('Caches cleared successfully!');
    }
}
