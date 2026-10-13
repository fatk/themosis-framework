<?php

namespace Themosis\Tests\Integration\WordPress;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Themosis\Route\Middleware\WordPressAuthorize;

class WordPressAuthorizeTest extends WordPressTestCase
{
    public static function requests(): array
    {
        return [
            'same-origin write' => ['editor', 'POST', [], ['HTTP_SEC_FETCH_SITE' => 'same-origin'], null],
            'write without fetch metadata' => ['editor', 'POST', [], [], null],
            'cross-site write' => ['editor', 'POST', [], ['HTTP_SEC_FETCH_SITE' => 'cross-site'], 403],
            'cross-site method override' => ['editor', 'POST', ['_method' => 'DELETE'], ['HTTP_SEC_FETCH_SITE' => 'cross-site'], 403],
            'foreign origin write' => ['editor', 'PUT', [], ['HTTP_ORIGIN' => 'https://attacker.invalid'], 403],
            'cross-site read' => ['editor', 'GET', [], ['HTTP_SEC_FETCH_SITE' => 'cross-site'], null],
            'missing capability' => ['subscriber', 'POST', [], ['HTTP_SEC_FETCH_SITE' => 'same-origin'], 404],
            'guest' => ['guest', 'GET', [], [], 404],
        ];
    }

    #[DataProvider('requests')]
    public function testCapabilityAndCrossSiteWrites(string $role, string $method, array $data, array $server, ?int $denial): void
    {
        wp_set_current_user($role === 'guest' ? 0 : $this->createUser($role));
        Request::enableHttpMethodParameterOverride();
        $request = Request::create('https://' . parse_url(home_url(), PHP_URL_HOST) . '/api/owned', $method, $data, [], [], $server);
        $called = false;

        try {
            (new WordPressAuthorize())->handle($request, function () use (&$called) {
                $called = true;

                return 'ok';
            }, 'edit_posts');
            self::assertNull($denial, 'The request should have been denied.');
            self::assertTrue($called);
        } catch (HttpException $exception) {
            self::assertSame($denial, $exception->getStatusCode());
            self::assertFalse($called);
        }
    }
}
