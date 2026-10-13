<?php

namespace Themosis\Tests\Core;

use Illuminate\Container\Container;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
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
        $first->finishAdmin();
        self::assertSame($first->request->session()->getId(), $first->cookieId());
        self::assertSame($value, AdminLifecycleHarness::stored($handler, $first->cookieId())['normal']);
        $second = new AdminLifecycleHarness($handler, $first->cookieId());
        $second->dispatchAdmin();
        $second->finishAdmin();
        self::assertSame($value, $second->probe->before['notice']);
        $third = new AdminLifecycleHarness($handler, $second->cookieId());
        $third->dispatchAdmin();
        $third->finishAdmin();
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

    public function testLateAdminWritesAreSavedOnceWithEarlyFlashAndTheKernelTerminatesOnce(): void
    {
        $handler = new class(120) extends ArraySessionHandler {
            public int $writes = 0;
            public function write($sessionId, $data): bool { $this->writes++; return parent::write($sessionId, $data); }
        };
        $value = bin2hex(random_bytes(8));
        $first = new AdminLifecycleHarness($handler, null, function ($session) use ($value) {
            $session->flash('early', $value);
        });
        $first->dispatchAdmin();
        self::assertSame(0, $handler->writes, 'The admin session is not saved before WordPress finishes.');
        self::assertSame(0, $first->terminations);

        // WordPress page callbacks, admin-post actions and redirects run after the kernel.
        $first->request->session()->put('late', $value);
        $first->request->session()->flash('late_flash', $value);
        $first->finishAdmin();
        $first->finishAdmin();

        self::assertSame(1, $handler->writes);
        self::assertSame(1, $first->terminations);
        self::assertSame(1, $first->probe->terminations);
        self::assertSame($first->request->session()->getId(), $first->cookieId());

        $second = new AdminLifecycleHarness($handler, $first->cookieId());
        $second->dispatchAdmin();
        $second->finishAdmin();
        self::assertSame($value, $second->probe->before['early']);
        self::assertSame($value, $second->probe->before['late_flash']);
        self::assertSame($value, $second->probe->before['late']);

        $third = new AdminLifecycleHarness($handler, $second->cookieId());
        $third->dispatchAdmin();
        $third->finishAdmin();
        self::assertArrayNotHasKey('early', $third->probe->before);
        self::assertArrayNotHasKey('late_flash', $third->probe->before);
        self::assertSame($value, $third->probe->before['late']);
    }

    public function testCustomSessionMiddlewareBindingIsNotReplaced(): void
    {
        $handler = new ArraySessionHandler(120);
        $first = new AdminLifecycleHarness($handler, null, function ($session) {
            $session->put('custom', true);
        });
        $first->app->singleton(StartSession::class, fn ($app) => new CustomStartSession($app->make(SessionManager::class)));
        $first->dispatchAdmin();

        self::assertTrue(AdminLifecycleHarness::stored($handler, $first->cookieId())['custom']);
        $first->finishAdmin();
        self::assertSame(1, $first->terminations);
    }
}

class CustomStartSession extends StartSession {}
