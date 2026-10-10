<?php

namespace Themosis\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class OptimizeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'optimize {--e|except= : Do not run the commands matching the key or name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cache the framework bootstrap files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $exceptions = Collection::make(explode(',', $this->option('except') ?? ''))
            ->map(fn ($except) => trim($except))
            ->filter()
            ->all();

        $tasks = Collection::make($this->getOptimizeTasks())
            ->reject(fn ($command, $key) => in_array($command, $exceptions) || in_array($key, $exceptions))
            ->all();

        foreach ($tasks as $command) {
            $this->call($command);
        }

        $this->info('Files cached successfully!');
    }

    /**
     * Get the commands that should be run to optimize the framework.
     *
     * @return array<string, string>
     */
    protected function getOptimizeTasks()
    {
        return [
            'config' => 'config:cache',
            'events' => 'event:cache',
            'routes' => 'route:cache',
            'views' => 'view:cache',
        ];
    }
}
