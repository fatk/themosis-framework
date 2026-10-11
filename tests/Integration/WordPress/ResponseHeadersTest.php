<?php

namespace Themosis\Tests\Integration\WordPress;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Themosis\Route\Middleware\WordPressHeaders;
use Themosis\Route\Route;

class ResponseHeadersTest extends WordPressTestCase
{
    public static function policies(): array
    {
        $cases = [];
        foreach ([false, true] as $condition) {
            foreach (['private, max-age=60', 'private, no-store, no-cache', 'no-cache', 'public, max-age=300'] as $policy) {
                $cases[] = [$condition, $policy];
            }
        }

        return $cases;
    }

    #[DataProvider('policies')]
    public function testRealWordPressGuestRetainsResponsePolicy(bool $condition, string $policy): void
    {
        wp_set_current_user(0);
        self::assertFalse(is_user_logged_in());
        $request = Request::create('/privacy');
        $route = new Route('GET', 'privacy', fn () => null);
        if ($condition) {
            $route->setConditions(['is_home' => 'privacy']);
        }
        $request->setRouteResolver(fn () => $route);
        $response = new Response('owned response', 200, ['Cache-Control' => $policy]);
        $expected = $response->headers->get('Cache-Control');

        self::assertSame($response, (new WordPressHeaders())->handle($request, fn () => $response));
        self::assertSame($expected, $response->headers->get('Cache-Control'));
    }

    public function testLaravelAuthenticatedGuestWithPersonalizedSessionRetainsPrivacy(): void
    {
        wp_set_current_user(0);
        self::assertFalse(is_user_logged_in());
        $request = Request::create('/account');
        $request->setUserResolver(fn () => (object) ['id' => 42]);
        $session = new \Illuminate\Session\Store('owned_privacy_test', new \Illuminate\Session\ArraySessionHandler(120));
        $session->put('cart', ['owned-example']);
        $request->setLaravelSession($session);
        $request->setRouteResolver(fn () => new Route('GET', 'account', fn () => null));
        $response = new Response(json_encode($request->session()->get('cart')), 200, ['Cache-Control' => 'private, no-store']);
        $expected = $response->headers->get('Cache-Control');

        (new WordPressHeaders())->handle($request, fn () => $response);

        self::assertSame($expected, $response->headers->get('Cache-Control'));
    }

    public function testLoggedInWordPressUserRetainsResponsePolicy(): void
    {
        wp_set_current_user($this->createUser('subscriber'));
        self::assertTrue(is_user_logged_in());
        $request = Request::create('/privacy');
        $request->setRouteResolver(fn () => new Route('GET', 'privacy', fn () => null));
        $response = new Response('owned response', 200, ['Cache-Control' => 'private, no-store']);
        $expected = $response->headers->get('Cache-Control');

        (new WordPressHeaders())->handle($request, fn () => $response);

        self::assertSame($expected, $response->headers->get('Cache-Control'));
    }
}
