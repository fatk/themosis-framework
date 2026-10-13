<?php

namespace Themosis\Tests\Integration\WordPress;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use PHPUnit\Framework\Attributes\DataProvider;

class RouteUrlTest extends WordPressTestCase
{
    public static function scripts(): array
    {
        return [
            'front controller' => ['/index.php', '/current'],
            'admin page' => ['/cms/wp-admin/admin.php', '/cms/wp-admin/admin.php?page=x'],
            'admin ajax' => ['/cms/wp-admin/admin-ajax.php', '/cms/wp-admin/admin-ajax.php'],
            'cron' => ['/cms/wp-cron.php', '/cms/wp-cron.php'],
        ];
    }

    #[DataProvider('scripts')]
    public function testAbsoluteNamedRoutesAreAnchoredOnTheWordPressHome(string $script, string $uri): void
    {
        $this->generator($script, $uri);
        $home = rtrim(home_url(), '/');

        self::assertSame($home . '/articles/12?q=a%20b', route('article', ['id' => 12, 'q' => 'a b']));
        self::assertSame('/articles/12?q=a%20b', route('article', ['id' => 12, 'q' => 'a b'], false));
    }

    public function testDomainRoutesAndForcedRootsKeepTheGeneratorUrl(): void
    {
        $url = $this->generator('/cms/wp-admin/admin.php', '/cms/wp-admin/admin.php');
        self::assertSame('https://alpha.mapped.test/tenant/12', route('tenant', ['tenant' => 'alpha', 'id' => 12]));

        $url->forceRootUrl('https://configured.test/base');
        self::assertSame('https://configured.test/base/articles/12', route('article', ['id' => 12]));
    }

    private function generator(string $script, string $uri): UrlGenerator
    {
        $request = Request::create('https://request.test' . $uri, 'GET', [], [], [], [
            'SCRIPT_NAME' => $script,
            'PHP_SELF' => $script,
            'SCRIPT_FILENAME' => '/fixture' . $script,
        ]);
        $routes = new RouteCollection();
        $routes->add(new Route('GET', 'articles/{id}', ['as' => 'article', 'uses' => fn () => 'article']));
        $routes->add((new Route('GET', 'tenant/{id}', ['as' => 'tenant', 'uses' => fn () => 'tenant']))->domain('{tenant}.mapped.test'));
        $url = new UrlGenerator($routes, $request);
        Container::setInstance($container = new Container());
        $container->instance('request', $request);
        $container->instance('url', $url);

        return $url;
    }
}
