<?php

namespace Themosis\Tests\Core;

use Illuminate\Container\Container;
use Illuminate\Session\ArraySessionHandler;
use PHPUnit\Framework\TestCase;
use Themosis\Tests\Support\AdminLifecycleHarness;

require_once dirname(__DIR__) . '/Support/AdminLifecycleHarness.php';

class AdminLifecycleTest extends TestCase
{
    private $previousContainer;

    protected function setUp(): void { $this->previousContainer = Container::getInstance(); }
    protected function tearDown(): void { Container::setInstance($this->previousContainer); }

    public function testCompleteRequestsPersistCookieIdentityAgeFlashOnceAndTerminateOnce(): void
    {
        $handler = new ArraySessionHandler(120);
        $value = bin2hex(random_bytes(8));
        $first = new AdminLifecycleHarness($handler, null, function ($session) use ($value) {
            $session->put('normal', $value);
            $session->flash('notice', $value);
        });
        $first->dispatchComplete();
        $id = $first->cookieId();
        self::assertSame($first->request->session()->getId(), $id);
        self::assertSame($value, AdminLifecycleHarness::stored($handler, $id)['normal']);
        self::assertSame(1, $first->probe->terminations);
        self::assertSame(1, $first->terminations);
        $second = new AdminLifecycleHarness($handler, $id);
        $second->dispatchComplete();
        self::assertSame($value, $second->probe->before['notice']);
        self::assertSame(1, $second->terminations);
        self::assertSame(1, $second->probe->terminations);
        $third = new AdminLifecycleHarness($handler, $second->cookieId());
        $third->dispatchComplete();
        self::assertArrayNotHasKey('notice', $third->probe->before);
        self::assertSame($value, $third->probe->before['normal']);
    }

    public function testEarlyAdminWritesPersistAndFlashSurvivesExactlyOneFollowingRequest(): void
    {
        $handler = new ArraySessionHandler(120);
        $value = bin2hex(random_bytes(8));
        $first = new AdminLifecycleHarness($handler, null, function ($session) use ($value) {
            $session->put('normal', $value); $session->flash('notice', $value);
        });
        $first->dispatchAdmin();
        self::assertSame($first->request->session()->getId(), $first->cookieId());
        self::assertSame($value, AdminLifecycleHarness::stored($handler, $first->cookieId())['normal']);
        $second = new AdminLifecycleHarness($handler, $first->cookieId());
        $second->dispatchAdmin();
        self::assertSame($value, $second->probe->before['notice']);
        $third = new AdminLifecycleHarness($handler, $second->cookieId());
        $third->dispatchAdmin();
        self::assertArrayNotHasKey('notice', $third->probe->before);
        self::assertSame($value, $third->probe->before['normal']);
    }

    public function testRegenerationInsideMiddlewarePersistsUsingTheIssuedCookie(): void
    {
        $handler = new ArraySessionHandler(120);
        $first = new AdminLifecycleHarness($handler, null);
        $first->dispatchComplete();
        $old = $first->cookieId();
        $second = new AdminLifecycleHarness($handler, $old, function ($session) {
            $session->regenerate(true); $session->put('regenerated', true);
        });
        $second->dispatchComplete();
        self::assertNotSame($old, $second->cookieId());
        self::assertSame('', $handler->read($old));
        $third = new AdminLifecycleHarness($handler, $second->cookieId());
        $third->dispatchComplete();
        self::assertTrue($third->probe->before['regenerated']);
    }
}
