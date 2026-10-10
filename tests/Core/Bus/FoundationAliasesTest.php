<?php

namespace Themosis\Tests\Core\Bus;

use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Bus\Dispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Foundation\Bus\PendingChain as FoundationPendingChain;
use Illuminate\Foundation\Events\Dispatchable as FoundationEventDispatchable;
use PHPUnit\Framework\TestCase;
use Themosis\Core\Application;
use Themosis\Core\Bus\PendingChain;
use Themosis\Core\Events\Dispatchable as EventDispatchable;

class FoundationAliasesTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    public function testBusChainReturnsTheThemosisPendingChain()
    {
        $app = new Application();
        $bus = new Dispatcher($app);
        $app->instance(DispatcherContract::class, $bus);
        ChainedJob::$handled = [];

        $chain = $bus->chain([new ChainedJob('first'), new ChainedJob('second')]);

        $this->assertInstanceOf(FoundationPendingChain::class, $chain);
        $this->assertInstanceOf(PendingChain::class, $chain);

        $first = $chain->job;
        $chain->dispatch();

        $this->assertSame(['first'], ChainedJob::$handled);
        $this->assertCount(1, $first->chained);
    }

    public function testAnonymousEventsCanBeCreated()
    {
        $event = new AnonymousEvent('orders');

        $this->assertTrue(trait_exists(FoundationEventDispatchable::class));
        $this->assertContains(EventDispatchable::class, class_uses($event));
    }
}

class ChainedJob
{
    use Queueable;

    public static array $handled = [];

    public function __construct(public string $name)
    {
    }

    public function handle()
    {
        static::$handled[] = $this->name;
    }
}
