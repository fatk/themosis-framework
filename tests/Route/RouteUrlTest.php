<?php

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RouteUrlTest extends TestCase
{
    private Container $previous;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        Container::setInstance(new Container());
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
    }

    public static function bases(): array
    {
        return [
            ['https://example.test', ''],
            ['https://example.test/subdirectory', '/subdirectory'],
            ['https://example.test/subdirectory/index.php', '/subdirectory/index.php'],
        ];
    }

    #[DataProvider('bases')]
    public function testNamedRoutePreservesRequestBase(string $root, string $base): void
    {
        $this->generator($root, $base);
        self::assertSame($root . '/articles/12?q=a%20b', route('article', ['id' => 12, 'q' => 'a b']));
        self::assertSame('/articles/12?q=a%20b', route('article', ['id' => 12, 'q' => 'a b'], false));
        self::assertSame('https://example.test/articles/12', rootUrl('articles/12'));
    }

    public function testForcedRootAndSchemeArePreserved(): void
    {
        $url = $this->generator('http://example.test', '');
        $url->forceRootUrl('http://configured.test/base');
        $url->forceScheme('https');
        self::assertSame('https://configured.test/base/articles/12', route('article', ['id' => 12]));
        self::assertSame('/base/articles/12', route('article', ['id' => 12], false));
    }

    public function testRouteDomainAndDomainParametersArePreserved(): void
    {
        $this->generator('https://example.test', '', '{tenant}.mapped.test');
        self::assertSame('https://alpha.mapped.test/articles/12?q=yes', route('article', ['tenant' => 'alpha', 'id' => 12, 'q' => 'yes']));
        self::assertSame('/articles/12?q=yes', route('article', ['tenant' => 'alpha', 'id' => 12, 'q' => 'yes'], false));
    }

    public function testFixedRouteDomainIsPreserved(): void
    {
        $this->generator('https://example.test', '', 'mapped.test');
        self::assertSame('https://mapped.test/articles/12', route('article', ['id' => 12]));
    }

    private function generator(string $root, string $base, ?string $domain = null): UrlGenerator
    {
        $request = Request::create($root . '/current', 'GET', [], [], [], [
            'SCRIPT_NAME' => $base ?: '/index.php',
            'PHP_SELF' => $base ?: '/index.php',
            'SCRIPT_FILENAME' => '/fixture/index.php',
        ]);
        // A directory base is represented by its front controller.
        if ($base && ! str_ends_with($base, 'index.php')) {
            $request->server->set('SCRIPT_NAME', $base . '/index.php');
            $request->server->set('PHP_SELF', $base . '/index.php');
        }
        $routes = new RouteCollection();
        $route = new Route('GET', 'articles/{id}', ['as' => 'article', 'uses' => fn () => 'article']);
        if ($domain) {
            $route->domain($domain);
        }
        $routes->add($route);
        $url = new UrlGenerator($routes, $request);
        app()->instance('request', $request);
        app()->instance('url', $url);

        return $url;
    }
}
