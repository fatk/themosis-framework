<?php

namespace Themosis\Tests\Auth;

use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Themosis\Auth\Console\AuthMakeCommand;
use Themosis\Core\Application;

class AuthMakeCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    public function testCommandRefusesToGenerateScaffoldingForRemovedTraits(): void
    {
        $app = new Application();
        $app['env'] = 'testing';
        $files = $this->createMock(Filesystem::class);
        $files->expects(self::never())->method('makeDirectory');
        $files->expects(self::never())->method('copy');
        $files->expects(self::never())->method('put');
        $files->expects(self::never())->method('append');
        $artisan = new Artisan($app, new Dispatcher($app), 'test');
        $artisan->setAutoExit(false);
        $artisan->addCommand(new AuthMakeCommand($files));

        self::assertSame(1, $artisan->call('make:auth'));
        self::assertStringContainsString('ui:auth', $artisan->output());
    }
}
