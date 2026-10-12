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

    protected function artisan(array &$ran, array $results = []): Artisan
    {
        $app = new Application();
        $app['env'] = 'testing';
        $artisan = new Artisan($app, new Dispatcher($app), 'test');
        $artisan->setAutoExit(false);
        $artisan->addCommand(new OptimizeCommand());

        foreach (['config:cache', 'event:cache', 'route:cache', 'view:cache'] as $name) {
            $artisan->addCommand(new ClosureCommand($name, function () use (&$ran, $name, $results) {
                $ran[] = $name;
                if (($results[$name] ?? 0) instanceof \Throwable) {
                    throw $results[$name];
                }

                return $results[$name] ?? 0;
            }));
        }

        return $artisan;
    }

    public static function failures(): array
    {
        return [
            ['config:cache', 2, ['config:cache']],
            ['event:cache', 7, ['config:cache', 'event:cache']],
            ['route:cache', 23, ['config:cache', 'event:cache', 'route:cache']],
            ['view:cache', 41, ['config:cache', 'event:cache', 'route:cache', 'view:cache']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testOptimizeStopsAndReturnsChildFailure(string $name, int $code, array $expected)
    {
        $ran = [];
        $artisan = $this->artisan($ran, [$name => $code]);

        $this->assertSame($code, $artisan->call('optimize'));
        $this->assertSame($expected, $ran);
        $this->assertStringContainsString($name, $artisan->output());
        $this->assertStringNotContainsString('Files cached successfully!', $artisan->output());
    }

    public function testOptimizeExcludesFailuresByKeyAndName()
    {
        $ran = [];
        $artisan = $this->artisan($ran, ['config:cache' => 2, 'view:cache' => 41]);

        $this->assertSame(0, $artisan->call('optimize', ['--except' => ' config, view:cache ']));
        $this->assertSame(['event:cache', 'route:cache'], $ran);
        $this->assertStringContainsString('Files cached successfully!', $artisan->output());
    }

    public function testOptimizePropagatesExceptionsAndStops()
    {
        $ran = [];
        $exception = new \RuntimeException('child failed');
        $artisan = $this->artisan($ran, ['event:cache' => $exception]);

        try {
            $artisan->call('optimize');
            $this->fail('Expected child exception');
        } catch (\RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }
        $this->assertSame(['config:cache', 'event:cache'], $ran);
        $this->assertStringNotContainsString('Files cached successfully!', $artisan->output());
    }

    public function testOptimizeCachesEverything()
    {
        $ran = [];

        $artisan = $this->artisan($ran);
        $this->assertSame(0, $artisan->call('optimize'));
        $this->assertStringContainsString('Files cached successfully!', $artisan->output());
        $this->assertSame(['config:cache', 'event:cache', 'route:cache', 'view:cache'], $ran);
    }

    public function testOptimizeSkipsTasksByKeyOrCommandName()
    {
        $ran = [];

        $this->assertSame(0, $this->artisan($ran)->call('optimize', ['--except' => 'routes, view:cache']));
        $this->assertSame(['config:cache', 'event:cache'], $ran);
    }
}
