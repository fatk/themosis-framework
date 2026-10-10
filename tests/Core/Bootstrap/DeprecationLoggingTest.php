<?php

namespace Themosis\Tests\Core\Bootstrap;

use ErrorException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Log\LogManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Themosis\Core\Application;
use Themosis\Core\Bootstrap\ExceptionHandler;

class DeprecationLoggingTest extends TestCase
{
    protected array $logged = [];

    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    protected function handler(mixed $deprecations, string $env = 'production'): ExceptionHandler
    {
        $app = new Application();
        $app['env'] = $env;
        $app->bootstrapWith([]);
        $app->instance('config', new Repository([
            'logging' => [
                'deprecations' => $deprecations,
                'channels' => [
                    'deprecations' => ['driver' => 'single'],
                    'single' => ['driver' => 'single'],
                    'null' => ['driver' => 'monolog'],
                ],
            ],
        ]));

        $logs = $this->createStub(LogManager::class);
        $logs->method('channel')->willReturnCallback(function ($channel) {
            $logger = $this->createStub(LoggerInterface::class);
            $logger->method('warning')->willReturnCallback(function ($message, array $context = []) use ($channel) {
                $this->logged[] = [$channel, $message, $context];
            });

            return $logger;
        });
        $app->instance(LogManager::class, $logs);

        return new class($app) extends ExceptionHandler {
            public function __construct($app)
            {
                $this->app = $app;
            }
        };
    }

    public function testDefaultChannelFollowsWordPressDebug()
    {
        $this->handler(null)->handleError(E_USER_DEPRECATED, 'Old API', 'theme.php', 12);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->assertSame([['deprecations', 'Old API in theme.php on line 12', []]], $this->logged);
        } else {
            $this->assertSame([], $this->logged);
        }
    }

    public function testExplicitChannelIsUsed()
    {
        $this->handler(['channel' => 'single'])->handleError(E_DEPRECATED, 'Old API', 'theme.php', 12);

        $this->assertSame([['single', 'Old API in theme.php on line 12', []]], $this->logged);
    }

    public function testChannelAsAPlainString()
    {
        $this->handler('single')->handleError(E_DEPRECATED, 'Old API', 'theme.php', 12);

        $this->assertSame('single', $this->logged[0][0]);
    }

    public function testFalseNullOrUnknownChannelsDiscardDeprecations()
    {
        $this->handler(['channel' => false])->handleError(E_DEPRECATED, 'Old API', 'theme.php', 12);
        $this->handler(['channel' => 'null'])->handleError(E_DEPRECATED, 'Old API', 'theme.php', 12);
        $this->handler(['channel' => 'missing'])->handleError(E_DEPRECATED, 'Old API', 'theme.php', 12);

        $this->assertSame([], $this->logged);
    }

    public function testTraceAddsTheExceptionToTheContext()
    {
        $this->handler(['channel' => 'single', 'trace' => true])->handleError(E_USER_DEPRECATED, 'Old API', 'theme.php', 12);

        [$channel, $message, $context] = $this->logged[0];
        $this->assertSame(['single', 'Old API'], [$channel, $message]);
        $this->assertInstanceOf(ErrorException::class, $context['exception']);
        $this->assertSame('theme.php', $context['exception']->getFile());
        $this->assertSame(12, $context['exception']->getLine());
    }

    public function testDeprecationsAreLoggedDespiteTheErrorReportingMask()
    {
        $reporting = error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        try {
            $this->assertTrue($this->handler('single')->handleError(E_USER_DEPRECATED, 'Masked', 'theme.php', 12));
        } finally {
            error_reporting($reporting);
        }

        $this->assertSame('Masked in theme.php on line 12', $this->logged[0][1]);
    }

    public function testDeprecationsAreIgnoredWhileRunningUnitTests()
    {
        $this->handler('single', 'testing')->handleError(E_DEPRECATED, 'Old API', 'theme.php', 12);

        $this->assertSame([], $this->logged);
    }
}
