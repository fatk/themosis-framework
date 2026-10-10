<?php

namespace Themosis\Tests\Core\Console;

use Illuminate\Config\Repository;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Themosis\Core\Application;
use Themosis\Core\Console\Kernel;

class KernelTest extends TestCase
{
    protected function tearDown(): void
    {
        Artisan::forgetBootstrappers();
        Container::setInstance(null);
    }

    protected function kernel(): ConsoleKernelStub
    {
        $app = new Application();
        $app['env'] = 'testing';
        $app->instance('config', new Repository(['app' => ['timezone' => 'UTC']]));
        $app->instance('events', $events = new Dispatcher($app));

        return new ConsoleKernelStub($app, $events);
    }

    public function testClosureCommandRunsAndMayReturnNothing()
    {
        $kernel = $this->kernel();

        $this->assertSame(0, $kernel->call('probe:closure', ['name' => 'world']));
        $this->assertStringContainsString('hello world', $kernel->output());
    }

    public function testAsCommandAttributeCommandIsLazilyResolved()
    {
        $kernel = $this->kernel();

        $this->assertArrayHasKey('probe:attribute', $kernel->all());
        $this->assertSame(0, $kernel->call('probe:attribute'));
        $this->assertStringContainsString('attribute ok', $kernel->output());
    }

    public function testFindCommandReturnsTheCommandOrNull()
    {
        $kernel = $this->kernel();
        $kernel->bootstrap();

        $this->assertInstanceOf(ProbeAttributeCommand::class, $kernel->findCommand('probe:attribute'));
        $this->assertNull($kernel->findCommand('probe:missing'));
    }
}

class ConsoleKernelStub extends Kernel
{
    protected $bootstrappers = [];

    protected $commands = [
        ProbeAttributeCommand::class,
    ];

    protected function commands()
    {
        $this->command('probe:closure {name}', function ($name) {
            $this->info("hello {$name}");
        });
    }
}

#[AsCommand(name: 'probe:attribute', description: 'Attribute command probe')]
class ProbeAttributeCommand extends Command
{
    protected $signature = 'probe:attribute';

    public function handle()
    {
        $this->info('attribute ok');
    }
}
