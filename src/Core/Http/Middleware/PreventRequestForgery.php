<?php

namespace Themosis\Core\Http\Middleware;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Arr;
use Illuminate\Support\InteractsWithTime;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel 13 request forgery protection: a request passes when it is
 * a "read" request, excluded, sent from the same origin (Sec-Fetch-Site)
 * or carries a valid CSRF token.
 */
class PreventRequestForgery
{
    use InteractsWithTime;

    /**
     * @var Application
     */
    protected $app;

    /**
     * @var Encrypter
     */
    protected $encrypter;

    /**
     * URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [];

    /**
     * The globally ignored URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected static $neverVerify = [];

    /**
     * Indicates whether the XSRF-TOKEN cookie should be set on the response.
     *
     * @var bool
     */
    protected $addHttpCookie = true;

    /**
     * Indicates whether requests from the same site should be allowed.
     *
     * @var bool
     */
    protected static $allowSameSite = false;

    /**
     * Indicates whether only origin verification should be used.
     *
     * @var bool
     */
    protected static $originOnly = false;

    public function __construct(Application $app, Encrypter $encrypter)
    {
        $this->app = $app;
        $this->encrypter = $encrypter;
    }

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @throws TokenMismatchException
     * @throws OriginMismatchException
     *
     * @return mixed
     */
    public function handle($request, \Closure $next)
    {
        if ($this->isReading($request) ||
            $this->runningUnitTests() ||
            $this->inExceptArray($request) ||
            $this->hasValidOrigin($request) ||
            $this->tokensMatch($request)
        ) {
            return tap($next($request), function ($response) use ($request) {
                if ($this->shouldAddXsrfTokenCookie()) {
                    $this->addCookieToResponse($request, $response);
                }
            });
        }

        throw new TokenMismatchException('CSRF token mismatch.');
    }

    /**
     * Determine if the HTTP request uses a "read" verb.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    protected function isReading(Request $request)
    {
        // The real method: a form POST must not pass as a read through _method.
        return in_array($request->getRealMethod(), ['HEAD', 'GET', 'OPTIONS']);
    }

    /**
     * Determine if the application is running unit tests.
     *
     * @return bool
     */
    protected function runningUnitTests()
    {
        return $this->app->runningInConsole() && $this->app->runningUnitTests();
    }

    /**
     * Check if the request has a URI that should pass through CSRF verification.
     *
     * @param Request $request
     *
     * @return bool
     */
    protected function inExceptArray(Request $request)
    {
        foreach ($this->getExcludedPaths() as $except) {
            if ('/' !== $except) {
                $except = trim($except, '/');
            }

            if ($request->fullUrlIs($except) || $request->is($except)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the request has a valid origin based on the Sec-Fetch-Site header.
     *
     * @param Request $request
     *
     * @throws OriginMismatchException
     *
     * @return bool
     */
    protected function hasValidOrigin(Request $request)
    {
        $secFetchSite = $request->header('Sec-Fetch-Site');

        if ('same-origin' === $secFetchSite) {
            return true;
        }

        if ('same-site' === $secFetchSite && static::$allowSameSite) {
            return true;
        }

        if (static::$originOnly) {
            throw new OriginMismatchException('Origin mismatch.');
        }

        return false;
    }

    /**
     * Check if the session and input CSRF tokens match.
     *
     * @param Request $request
     *
     * @return bool
     */
    protected function tokensMatch(Request $request)
    {
        $token = $this->getTokenFromRequest($request);

        return is_string($request->session()->token()) &&
            is_string($token) &&
            hash_equals($request->session()->token(), $token);
    }

    /**
     * Return the CSRF token from request.
     *
     * @param Request $request
     *
     * @return string|null
     */
    protected function getTokenFromRequest(Request $request)
    {
        $token = $request->input('_token') ?: $request->header('X-CSRF-TOKEN');

        if (! $token && $header = $request->header('X-XSRF-TOKEN')) {
            try {
                $token = CookieValuePrefix::remove($this->encrypter->decrypt($header, static::serialized()));
            } catch (DecryptException) {
                $token = '';
            }
        }

        return $token;
    }

    /**
     * Determine if the cookie should be added to the response.
     *
     * @return bool
     */
    public function shouldAddXsrfTokenCookie()
    {
        if (static::$originOnly) {
            return false;
        }

        return $this->addHttpCookie;
    }

    /**
     * Add the CSRF token to the response cookies.
     *
     * @param Request  $request
     * @param Response $response
     *
     * @return Response
     */
    protected function addCookieToResponse(Request $request, Response $response)
    {
        $config = config('session');

        if ($response instanceof Responsable) {
            $response = $response->toResponse($request);
        }

        $response->headers->setCookie($this->newCookie($request, $config));

        return $response;
    }

    /**
     * Create a new "XSRF-TOKEN" cookie that contains the CSRF token.
     *
     * @param Request $request
     * @param array   $config
     *
     * @return Cookie
     */
    protected function newCookie(Request $request, array $config)
    {
        return new Cookie(
            'XSRF-TOKEN',
            $request->session()->token(),
            $this->availableAt(60 * $config['lifetime']),
            $config['path'],
            $config['domain'],
            $config['secure'],
            false,
            false,
            $config['same_site'] ?? null,
            $config['partitioned'] ?? false,
        );
    }

    /**
     * Indicate that the given URIs should be excluded from CSRF verification.
     *
     * @param array|string $uris
     *
     * @return void
     */
    public static function except($uris)
    {
        static::$neverVerify = array_values(array_unique(
            array_merge(static::$neverVerify, Arr::wrap($uris)),
        ));
    }

    /**
     * Indicate that requests from the same site should be allowed.
     *
     * @param bool $allow
     *
     * @return void
     */
    public static function allowSameSite($allow = true)
    {
        static::$allowSameSite = $allow;
    }

    /**
     * Indicate that only origin verification should be used.
     *
     * @param bool $originOnly
     *
     * @return void
     */
    public static function useOriginOnly($originOnly = true)
    {
        static::$originOnly = $originOnly;
    }

    /**
     * Get the URIs that should be excluded.
     *
     * @return array
     */
    public function getExcludedPaths()
    {
        return array_merge($this->except, static::$neverVerify);
    }

    /**
     * Determine if the cookie contents should be serialized.
     *
     * @return bool
     */
    public static function serialized()
    {
        return EncryptCookies::serialized('XSRF-TOKEN');
    }

    /**
     * Flush the state of the middleware.
     *
     * @return void
     */
    public static function flushState()
    {
        static::$neverVerify = [];
        static::$allowSameSite = false;
        static::$originOnly = false;
    }
}
