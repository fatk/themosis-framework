<?php

namespace Themosis\Support;

use Symfony\Component\HttpFoundation\Request;

/**
 * Minimal cross-site request detection for state-changing endpoints
 * that cannot rely on a CSRF token (WordPress admin-post actions,
 * the metabox API, framework forms).
 */
class RequestOrigin
{
    /**
     * Determine if the browser reports the request as sent from another site.
     *
     * Uses the Sec-Fetch-Site header sent by modern browsers, and falls
     * back to comparing the Origin header host. Requests without either
     * header (legacy clients, server-to-server calls) are not rejected.
     */
    public static function isCrossSite(?Request $request = null): bool
    {
        $request ??= Request::createFromGlobals();

        $site = $request->headers->get('Sec-Fetch-Site');

        if (! is_null($site)) {
            return 'cross-site' === strtolower($site);
        }

        $origin = $request->headers->get('Origin');

        if (is_null($origin) || ! is_string($host = parse_url($origin, PHP_URL_HOST))) {
            return false;
        }

        return strtolower($host) !== strtolower($request->getHost());
    }
}
