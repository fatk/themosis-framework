<?php

namespace Themosis\Tests\Core\Console;

use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Themosis\Core\Application;
use Themosis\Core\Console\ClosureCommand;
use Themosis\Core\Console\OptimizeCommand;

class OptimizeCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    protected function artisan(array &$ran): Artisan
    {
        $app = new Application();
        $app['env'] = 'testing';
        $artisan = new Artisan($app, new Dispatcher($app), 'test');
        $artisan->setAutoExit(false);
        $artisan->addCommand(new OptimizeCommand());

        foreach (['config:cache', 'event:cache', 'route:cache', 'view:cache'] as $name) {
            $artisan->addCommand(new ClosureCommand($name, function () use (&$ran, $name) {
                $ran[] = $name;
            }));
        }

        return $artisan;
    }

    public function testOptimizeCachesEverything()
    {
        $ran = [];

        $this->assertSame(0, $this->artisan($ran)->call('optimize'));
        $this->assertSame(['config:cache', 'event:cache', 'route:cache', 'view:cache'], $ran);
    }

    public function testOptimizeSkipsTasksByKeyOrCommandName()
    {
        $ran = [];

        $this->assertSame(0, $this->artisan($ran)->call('optimize', ['--except' => 'routes, view:cache']));
        $this->assertSame(['config:cache', 'event:cache'], $ran);
    }
}
