<?php

namespace Themosis\Tests\Core\Console;

use Dotenv\Dotenv;
use Illuminate\Config\Repository;
use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Themosis\Core\Application;
use Themosis\Core\Console\SaltsGenerateCommand;

class SaltsGenerateCommandTest extends TestCase
{
    private const KEYS = ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'];

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/themosis-salts-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/.env');
        @rmdir($this->directory);
        Container::setInstance(null);
    }

    public function testEverySaltIsReplacedWithAValueDotenvReadsBackVerbatim(): void
    {
        $env = "APP_ENV=testing\n";
        $config = [];
        foreach (self::KEYS as $key) {
            $env .= $key . '="old-' . strtolower($key) . "\"\n";
            $config[strtolower($key)] = 'old-' . strtolower($key);
        }
        file_put_contents($this->directory . '/.env', $env);

        $app = new Application($this->directory);
        $app['env'] = 'testing';
        $app->instance('config', new Repository(['app' => ['salts' => $config]]));
        $artisan = new Artisan($app, new Dispatcher($app), 'test');
        $artisan->setAutoExit(false);
        $artisan->addCommand(new SaltsGenerateCommand());

        // Repeat to make backreference-like and interpolation-like salts likely.
        for ($run = 0; $run < 5; $run++) {
            self::assertSame(0, $artisan->call('salts:generate', ['--show' => true]));
            preg_match_all('/^([A-Z_]+)=(.*)$/m', $artisan->output(), $shown, PREG_SET_ORDER);
            $shown = array_column($shown, 2, 1);
            $parsed = Dotenv::parse(file_get_contents($this->directory . '/.env'));

            foreach (self::KEYS as $key) {
                self::assertSame(64, strlen($shown[$key]));
                self::assertStringNotContainsString('$', $shown[$key]);
                self::assertSame($shown[$key], $parsed[$key], $key . ' must be written verbatim.');
                $config[strtolower($key)] = $shown[$key];
            }
            $app['config']->set('app.salts', $config);
        }
    }
}
