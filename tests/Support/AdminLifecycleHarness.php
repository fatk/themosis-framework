<?php

namespace Themosis\Tests\Support;

use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use Themosis\Core\Application;
use Themosis\Core\Http\Kernel;

/** Fresh application per request, real router/kernel/session middleware, shared owned storage. */
class AdminLifecycleHarness
{
    public Application $app;
    public Request $request;
    public Kernel $kernel;
    public LifecycleProbe $probe;
    public $response;
    public int $terminations = 0;

    public function __construct(ArraySessionHandler $handler, ?string $id, ?callable $early = null)
    {
        $this->app = new class extends Application {
            public function isWordPressAdmin() { return true; }
            public function dispatchForTest($kernel, $request) { return $this->dispatchToAdmin($kernel, $request); }
        };
        $this->app->instance('config', new Repository(['app' => ['wp' => ['dir' => 'cms']], 'session' => [
            'driver' => 'owned', 'cookie' => 'owned_lifecycle', 'lifetime' => 120, 'lottery' => [0, 100],
            'path' => '/', 'domain' => null, 'secure' => true, 'http_only' => true,
            'same_site' => 'lax', 'expire_on_close' => true,
        ]]));
        $manager = new SessionManager($this->app);
        $manager->extend('owned', fn () => $handler);
        $this->app->instance('session', $manager);
        $this->app->instance(SessionManager::class, $manager);
        $this->probe = new LifecycleProbe($early);
        $this->app->instance(LifecycleProbe::class, $this->probe);
        $this->kernel = new class($this->app, $this->app['router']) extends Kernel {
            protected $middlewareGroups = ['admin' => [StartSession::class, LifecycleProbe::class]];
        };
        $this->app->instance(Kernel::class, $this->kernel);
        $this->app->terminating(function () { $this->terminations++; });
        $this->request = Request::create('https://example.test/cms/wp-admin/tools.php', 'GET', [], $id ? ['owned_lifecycle' => $id] : []);
    }

    public function dispatchAdmin(): void
    {
        ($this->app->dispatchForTest(Kernel::class, $this->request))();
        $this->response = $this->probe->response;
    }

    public function dispatchComplete(): void
    {
        $this->app->manage(Kernel::class, $this->request);
        $this->response = $this->probe->response;
    }

    public function cookieId(): string
    {
        return $this->response->headers->getCookies()[0]->getValue();
    }

    public static function stored(ArraySessionHandler $handler, string $id): array
    {
        $store = new Store('owned_lifecycle', $handler, $id);
        $store->start();
        return $store->all();
    }
}

class LifecycleProbe
{
    public array $before = [];
    public int $terminations = 0;
    public $response;

    public function __construct(private $early) {}

    public function handle($request, \Closure $next)
    {
        $this->before = $request->session()->all();
        if ($this->early) { ($this->early)($request->session()); }
        return $this->response = $next($request);
    }

    public function terminate($request, $response): void { $this->terminations++; }
}
