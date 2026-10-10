<?php

namespace Themosis\Tests\Core;

use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\TestCase;
use Themosis\Core\Application;
use Themosis\Core\Maintenance\WordPressMaintenanceMode;

class ApplicationContractTest extends TestCase
{
    public function testRegisterHonoursTheContractForceFlag()
    {
        $app = new Application();
        CountingServiceProvider::$registered = 0;

        $app->register(CountingServiceProvider::class);
        $app->register(CountingServiceProvider::class);
        $this->assertSame(1, CountingServiceProvider::$registered);

        $app->register(CountingServiceProvider::class, true);
        $this->assertSame(2, CountingServiceProvider::$registered);
    }

    public function testRegisterKeepsTheLegacyOptionsSignature()
    {
        $app = new Application();
        CountingServiceProvider::$registered = 0;

        $app->register(CountingServiceProvider::class);
        $app->register(CountingServiceProvider::class, [], true);

        $this->assertSame(2, CountingServiceProvider::$registered);
    }

    public function testPublicPathIsTheWebRoot()
    {
        $app = new Application(realpath(__DIR__ . '/../'));

        $this->assertSame($app['path.public'], $app->publicPath());
        $this->assertSame($app->webPath('dist'), $app->publicPath('dist'));

        $app->usePublicPath('/custom/public/');
        $this->assertSame('/custom/public', $app->publicPath());
        $this->assertSame('/custom/public' . DIRECTORY_SEPARATOR . 'dist', $app->publicPath('dist'));
    }

    public function testStoragePathHonoursUseStoragePath()
    {
        $app = new Application(realpath(__DIR__ . '/../'));
        $app->useStoragePath('/custom/storage');

        $this->assertSame('/custom/storage', $app->storagePath());
        $this->assertSame('/custom/storage' . DIRECTORY_SEPARATOR . 'logs', $app->storagePath('logs'));
        $this->assertSame('/custom/storage', $app['path.storage']);
    }

    public function testMaintenanceModeIsImplemented()
    {
        $app = new Application(realpath(__DIR__ . '/../'));

        $this->assertInstanceOf(MaintenanceMode::class, $app->maintenanceMode());
        $this->assertInstanceOf(WordPressMaintenanceMode::class, $app->maintenanceMode());
    }

    public function testWordPressMaintenanceModeLifecycle()
    {
        $dir = sys_get_temp_dir() . '/themosis_maintenance_' . uniqid();
        mkdir($dir);
        $maintenanceFile = $dir . '/.maintenance';
        $payloadFile = $dir . '/down';

        $mode = new WordPressMaintenanceMode($maintenanceFile, $payloadFile);
        $this->assertFalse($mode->active());
        $this->assertSame([], $mode->data());

        $mode->activate(['retry' => 60, 'secret' => 'abc']);
        $this->assertTrue($mode->active());
        $this->assertSame(['retry' => 60, 'secret' => 'abc'], $mode->data());

        // WordPress includes .maintenance as PHP: it must only define $upgrading.
        $upgrading = null;
        include $maintenanceFile;
        $this->assertIsInt($upgrading);

        $mode->deactivate();
        $this->assertFalse($mode->active());
        $this->assertFileDoesNotExist($maintenanceFile);
        $this->assertFileDoesNotExist($payloadFile);

        rmdir($dir);
    }

    public function testTwigEnvironmentIsAnAliasOfTheTwigBinding()
    {
        $app = new Application();
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader());
        $app->instance('twig', $twig);

        $this->assertSame('twig', $app->getAlias(\Twig\Environment::class));
        $this->assertSame($twig, $app->make(\Twig\Environment::class));
    }

    public function testEnvironmentHelpersUsedByLaravelComponents()
    {
        $app = new Application(realpath(__DIR__ . '/../'));

        $app['env'] = 'production';
        $this->assertTrue($app->isProduction());
        $this->assertFalse($app->isLocal());

        $app['env'] = 'local';
        $this->assertTrue($app->isLocal());
        $this->assertFalse($app->isProduction());

        $this->assertSame($app->bootstrapPath('providers.php'), $app->getBootstrapProvidersPath());
    }

    public function testRunningConsoleCommand()
    {
        $app = new Application();
        $argv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'route:cache'];

        try {
            $this->assertTrue($app->runningConsoleCommand('route:cache'));
            $this->assertTrue($app->runningConsoleCommand(['config:cache', 'route:cache']));
            $this->assertFalse($app->runningConsoleCommand('view:cache'));
        } finally {
            $_SERVER['argv'] = $argv;
        }
    }

    public function testTerminatingCallbacksRunWhenTheApplicationTerminates()
    {
        $app = new Application();
        $ran = [];

        $this->assertSame($app, $app->terminating(function () use (&$ran, $app) {
            $ran[] = 'first';

            $app->terminating(function () use (&$ran) {
                $ran[] = 'registered while terminating';
            });
        }));

        $app->terminate();

        $this->assertSame(['first', 'registered while terminating'], $ran);
    }

    public function testJobsDispatchedAfterResponseRunOnTerminate()
    {
        $app = new Application();
        $bus = new Dispatcher($app);
        TerminatingJob::$handled = false;

        $bus->dispatchAfterResponse(new TerminatingJob());
        $this->assertFalse(TerminatingJob::$handled);

        $app->terminate();
        $this->assertTrue(TerminatingJob::$handled);
    }
}

class CountingServiceProvider extends ServiceProvider
{
    public static int $registered = 0;

    public function register()
    {
        static::$registered++;
    }
}

class TerminatingJob
{
    public static bool $handled = false;

    public function handle()
    {
        static::$handled = true;
    }
}
