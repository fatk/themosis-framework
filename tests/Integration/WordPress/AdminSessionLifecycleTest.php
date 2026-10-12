<?php

namespace Themosis\Tests\Integration\WordPress;

use Illuminate\Session\ArraySessionHandler;
use Themosis\Core\Http\Kernel;
use Themosis\Tests\Support\AdminLifecycleHarness;

require_once dirname(__DIR__, 2) . '/Support/AdminLifecycleHarness.php';

class AdminSessionLifecycleTest extends WordPressTestCase
{
    public function testWordPressAdminInitPrecedesPagePostAndRetainsEarlySessionIdentity(): void
    {
        wp_set_current_user($this->createUser('administrator'));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $original = app();
        $action = $original['action'];
        // Isolate this request's queue: the test process has already booted the fixture.
        $GLOBALS['wp_filter']['admin_init'] = new \WP_Hook();
        $events = [];
        $page = $original['page']->make($this->ownership, 'Owned lifecycle');
        $handler = new ArraySessionHandler(120);
        $request = new AdminLifecycleHarness($handler, null, function ($session) use (&$events) {
            $events[] = 'kernel-session';
            $session->put('early', $this->ownership);
        });
        $request->app->instance('action', $action);
        $request->app->manageAdmin(Kernel::class, $request->request);
        $page->route('save', function () use ($request, &$events) {
            $events[] = 'page-post';
            self::assertSame($this->ownership, $request->request->session()->get('early'));
            $request->request->session()->put('late', $this->ownership);
        }, 'post');
        try {
            do_action('admin_init');
            self::assertSame(['kernel-session'], $events);
            do_action('admin_post_' . $page->getAction('save'));
            self::assertSame(['kernel-session', 'page-post'], $events);
            self::assertSame($this->ownership, $request->request->session()->get('late'));
            $response = $request->probe->response;
            $id = $response->headers->getCookies()[0]->getValue();
            self::assertSame($request->request->session()->getId(), $id);
            self::assertSame($this->ownership, AdminLifecycleHarness::stored($handler, $id)['early']);
        } finally {
            $original->offsetUnset('page.' . $page->getSlug());
        }
    }

    public function testEarlyFlashAcrossFreshAdminRequestsWithRealWordPressHooks(): void
    {
        $action = app('action');
        $handler = new ArraySessionHandler(120);
        $id = null;
        for ($step = 0; $step < 3; $step++) {
            $GLOBALS['wp_filter']['admin_init'] = new \WP_Hook();
            $request = new AdminLifecycleHarness($handler, $id, $step === 0 ? function ($session) {
                $session->flash('notice', $this->ownership);
                $session->put('normal', $this->ownership);
            } : null);
            $request->app->instance('action', $action);
            $request->app->manageAdmin(Kernel::class, $request->request);
            do_action('admin_init');
            $id = $request->probe->response->headers->getCookies()[0]->getValue();
            self::assertSame($request->request->session()->getId(), $id);
            if ($step === 1) { self::assertSame($this->ownership, $request->probe->before['notice']); }
            if ($step === 2) {
                self::assertArrayNotHasKey('notice', $request->probe->before);
                self::assertSame($this->ownership, $request->probe->before['normal']);
            }
        }
    }
}
