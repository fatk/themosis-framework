<?php

namespace {
    function is_user_logged_in(): bool
    {
        return false;
    }
}

namespace Themosis\Tests\Route {
    use Illuminate\Http\Request;
    use Illuminate\Http\Response;
    use Illuminate\Session\ArraySessionHandler;
    use Illuminate\Session\Store;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Themosis\Route\Middleware\WordPressHeaders;
    use Themosis\Route\Route;

    class WordPressHeadersTest extends TestCase
    {
        public static function policies(): array
        {
            return [
                'private' => ['private, max-age=60'],
                'no store' => ['private, no-store, no-cache'],
                'no cache' => ['no-cache'],
                'explicit public' => ['public, max-age=300'],
            ];
        }

        #[DataProvider('policies')]
        public function testGuestResponseRetainsExplicitPolicy(string $policy): void
        {
            $request = Request::create('/privacy');
            $request->setRouteResolver(fn () => new Route('GET', 'privacy', fn () => null));
            $response = new Response('content', 200, ['Cache-Control' => $policy]);
            $expected = $response->headers->get('Cache-Control');

            self::assertSame($response, (new WordPressHeaders())->handle($request, fn () => $response));
            self::assertSame($expected, $response->headers->get('Cache-Control'));
        }

        public function testLaravelAuthenticatedWordPressGuestRetainsPrivacy(): void
        {
            $request = Request::create('/account');
            $request->setUserResolver(fn () => (object) ['id' => 42]);
            $request->setRouteResolver(fn () => new Route('GET', 'account', fn () => null));
            $response = new Response('account');
            $response->setPrivate();

            (new WordPressHeaders())->handle($request, fn () => $response);

            self::assertTrue($response->headers->hasCacheControlDirective('private'));
            self::assertFalse($response->headers->hasCacheControlDirective('public'));
        }

        public function testPersonalizedGuestSessionRetainsPrivacy(): void
        {
            $request = Request::create('/cart');
            $session = new Store('privacy_test', new ArraySessionHandler(120));
            $session->put('cart', ['owned-example']);
            $request->setLaravelSession($session);
            $request->setRouteResolver(fn () => new Route('GET', 'cart', fn () => null));
            $response = new Response(json_encode($request->session()->get('cart')));
            $expected = $response->headers->get('Cache-Control');

            (new WordPressHeaders())->handle($request, fn () => $response);

            self::assertSame($expected, $response->headers->get('Cache-Control'));
            self::assertFalse($response->headers->hasCacheControlDirective('public'));
        }
    }
}
