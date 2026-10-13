<?php

namespace Themosis\Tests\Core\Console;

use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Themosis\Core\Application;
use Themosis\Core\Console\ClosureCommand;
use Themosis\Core\Console\OptimizeClearCommand;

class OptimizeClearCommandTest extends TestCase
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
        $artisan->addCommand(new OptimizeClearCommand());

        foreach (['view:clear', 'cache:clear', 'route:clear', 'event:clear', 'config:clear', 'clear-compiled'] as $name) {
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
        $tasks = ['view:clear', 'cache:clear', 'route:clear', 'event:clear', 'config:clear', 'clear-compiled'];
        $cases = [];
        foreach ($tasks as $index => $name) {
            $cases[] = [$name, $index + 2, $tasks];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testOptimizeClearRunsEveryTaskAndReturnsChildFailure(string $name, int $code, array $expected)
    {
        $ran = [];
        $artisan = $this->artisan($ran, [$name => $code]);

        $this->assertSame($code, $artisan->call('optimize:clear'));
        $this->assertSame($expected, $ran);
        $this->assertStringContainsString($name, $artisan->output());
        $this->assertStringNotContainsString('Caches cleared successfully!', $artisan->output());
    }

    public function testOptimizeClearReturnsTheFirstOfSeveralFailures()
    {
        $ran = [];
        $artisan = $this->artisan($ran, ['cache:clear' => 3, 'config:clear' => 5]);

        $this->assertSame(3, $artisan->call('optimize:clear'));
        $this->assertSame(['view:clear', 'cache:clear', 'route:clear', 'event:clear', 'config:clear', 'clear-compiled'], $ran);
        $output = $artisan->output();
        $this->assertStringContainsString('cache:clear', $output);
        $this->assertStringContainsString('config:clear', $output);
        $this->assertStringNotContainsString('Caches cleared successfully!', $output);
    }

    public function testOptimizePropagatesExceptionsAndStops()
    {
        $ran = [];
        $exception = new \RuntimeException('child failed');
        $artisan = $this->artisan($ran, ['cache:clear' => $exception]);

        try {
            $artisan->call('optimize:clear');
            $this->fail('Expected child exception');
        } catch (\RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }
        $this->assertSame(['view:clear', 'cache:clear'], $ran);
        $this->assertStringNotContainsString('Caches cleared successfully!', $artisan->output());
    }

    public function testOptimizeClearsEverything()
    {
        $ran = [];

        $artisan = $this->artisan($ran);
        $this->assertSame(0, $artisan->call('optimize:clear'));
        $this->assertStringContainsString('Caches cleared successfully!', $artisan->output());
        $this->assertSame(['view:clear', 'cache:clear', 'route:clear', 'event:clear', 'config:clear', 'clear-compiled'], $ran);
    }
}
