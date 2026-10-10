<?php

namespace Themosis\Tests\Core\Http;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Session\TokenMismatchException;
use PHPUnit\Framework\TestCase;
use Themosis\Core\Application;
use Themosis\Core\Http\Middleware\PreventRequestForgery;
use Themosis\Core\Http\Middleware\VerifyCsrfToken;

class PreventRequestForgeryTest extends TestCase
{
    protected Application $app;

    protected Encrypter $encrypter;

    protected Store $session;

    protected function setUp(): void
    {
        $this->app = new Application();
        $this->app['env'] = 'production';
        $this->app->instance('config', new Repository([
            'session' => [
                'lifetime' => 120,
                'path' => '/',
                'domain' => null,
                'secure' => false,
                'same_site' => 'lax',
            ],
        ]));
        Container::setInstance($this->app);

        $this->encrypter = new Encrypter(random_bytes(32), 'AES-256-CBC');
        $this->session = new Store('test', new ArraySessionHandler(10));
        $this->session->start();
    }

    protected function tearDown(): void
    {
        PreventRequestForgery::flushState();
        Container::setInstance(null);
    }

    protected function request(string $method, array $parameters = [], array $headers = [], string $uri = '/form'): Request
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create($uri, $method, $parameters, [], [], $server);
        $request->setLaravelSession($this->session);

        return $request;
    }

    protected function handle(Request $request, ?PreventRequestForgery $middleware = null): Response
    {
        $middleware ??= new PreventRequestForgery($this->app, $this->encrypter);

        return $middleware->handle($request, fn () => new Response('ok'));
    }

    public function testReadingRequestsPassAndReceiveTheXsrfCookie()
    {
        $response = $this->handle($this->request('GET'));

        $this->assertSame('ok', $response->getContent());
        $this->assertSame('XSRF-TOKEN', $response->headers->getCookies()[0]->getName());
        $this->assertSame($this->session->token(), $response->headers->getCookies()[0]->getValue());
    }

    public function testRequestWithoutTokenOrOriginIsRejected()
    {
        $this->expectException(TokenMismatchException::class);

        $this->handle($this->request('POST'));
    }

    public function testCrossSiteRequestWithoutTokenIsRejected()
    {
        $this->expectException(TokenMismatchException::class);

        $this->handle($this->request('POST', [], ['Sec-Fetch-Site' => 'cross-site']));
    }

    public function testSameOriginRequestPassesWithoutToken()
    {
        $response = $this->handle($this->request('POST', [], ['Sec-Fetch-Site' => 'same-origin']));

        $this->assertSame('ok', $response->getContent());
    }

    public function testSameSiteRequestNeedsToBeAllowed()
    {
        try {
            $this->handle($this->request('POST', [], ['Sec-Fetch-Site' => 'same-site']));
            $this->fail('Same-site request without token should be rejected by default.');
        } catch (TokenMismatchException) {
        }

        PreventRequestForgery::allowSameSite();

        $response = $this->handle($this->request('POST', [], ['Sec-Fetch-Site' => 'same-site']));
        $this->assertSame('ok', $response->getContent());
    }

    public function testOriginOnlyModeThrowsOriginMismatchAndSkipsTheCookie()
    {
        PreventRequestForgery::useOriginOnly();

        $response = $this->handle($this->request('POST', [], ['Sec-Fetch-Site' => 'same-origin']));
        $this->assertSame([], $response->headers->getCookies());

        $this->expectException(OriginMismatchException::class);
        $this->handle($this->request('POST', ['_token' => $this->session->token()], ['Sec-Fetch-Site' => 'cross-site']));
    }

    public function testTokenFromInputOrHeaderIsAccepted()
    {
        $token = $this->session->token();

        $this->assertSame('ok', $this->handle($this->request('POST', ['_token' => $token]))->getContent());
        $this->assertSame('ok', $this->handle($this->request('POST', [], ['X-CSRF-TOKEN' => $token]))->getContent());
    }

    public function testEncryptedXsrfHeaderIsAccepted()
    {
        $value = CookieValuePrefix::create('XSRF-TOKEN', $this->encrypter->getKey()).$this->session->token();
        $header = $this->encrypter->encrypt($value, PreventRequestForgery::serialized());

        $response = $this->handle($this->request('POST', [], ['X-XSRF-TOKEN' => $header]));

        $this->assertSame('ok', $response->getContent());
    }

    public function testInvalidXsrfHeaderIsRejected()
    {
        $this->expectException(TokenMismatchException::class);

        $this->handle($this->request('POST', [], ['X-XSRF-TOKEN' => 'not-encrypted']));
    }

    public function testExcludedUrisPass()
    {
        $middleware = new class($this->app, $this->encrypter) extends PreventRequestForgery {
            protected $except = ['webhook/*'];
        };

        $this->assertSame('ok', $this->handle($this->request('POST', [], [], '/webhook/stripe'), $middleware)->getContent());

        PreventRequestForgery::except('/callback');
        $this->assertSame('ok', $this->handle($this->request('POST', [], [], '/callback'))->getContent());
    }

    public function testVerifyCsrfTokenIsTheDeprecatedName()
    {
        $middleware = new VerifyCsrfToken($this->app, $this->encrypter);

        $this->assertInstanceOf(PreventRequestForgery::class, $middleware);
        $this->assertSame('ok', $this->handle($this->request('POST', [], ['Sec-Fetch-Site' => 'same-origin']), $middleware)->getContent());
    }
}
