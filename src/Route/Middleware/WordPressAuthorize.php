<?php

namespace Themosis\Route\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Themosis\Support\RequestOrigin;

class WordPressAuthorize
{
    /**
     * Handle incoming request.
     *
     * @param Request  $request
     * @param \Closure $next
     * @param string   $capability
     *
     * @throws HttpException
     *
     * @return mixed
     */
    public function handle($request, \Closure $next, $capability = 'edit_posts')
    {
        // The WordPress auth cookie is the only credential here: refuse
        // cross-site writes (CSRF). Same-origin requests are unaffected.
        if (! in_array($request->getRealMethod(), ['GET', 'HEAD', 'OPTIONS'], true) && RequestOrigin::isCrossSite($request)) {
            throw new HttpException(403);
        }

        /**
         * Verify that current user is logged in and has appropriate capability.
         */
        if (is_user_logged_in() && current_user_can($capability)) {
            return $next($request);
        }

        throw new HttpException(404);
    }
}
