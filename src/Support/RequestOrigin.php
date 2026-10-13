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
     * Determine if the browser reports the request as sent from another site
     * (or a sibling subdomain), mirroring PreventRequestForgery's defaults.
     *
     * Uses the Sec-Fetch-Site header sent by modern browsers, and falls
     * back to comparing the Origin header host. Requests without either
     * header (very old browsers, server-to-server calls without the
     * victim's cookies) are not rejected, so existing clients keep working.
     */
    public static function isCrossSite(?Request $request = null): bool
    {
        $request ??= Request::createFromGlobals();

        $site = $request->headers->get('Sec-Fetch-Site');

        if (! is_null($site)) {
            return ! in_array(strtolower($site), ['same-origin', 'none'], true);
        }

        $origin = $request->headers->get('Origin');

        if (is_null($origin) || ! is_string($host = parse_url($origin, PHP_URL_HOST))) {
            return false;
        }

        return strtolower($host) !== strtolower($request->getHost());
    }
}
