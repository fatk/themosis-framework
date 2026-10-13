<?php

namespace Themosis\Core\Http\Middleware;

use Illuminate\Session\Middleware\StartSession;

/**
 * Session middleware for WordPress administration requests.
 *
 * The admin kernel runs on "admin_init", before WordPress page callbacks,
 * admin-post actions and redirects. Saving when the middleware returns
 * would drop later session writes, and saving twice would age flash data
 * twice. The session is therefore saved once, when the kernel terminates
 * after WordPress has finished the request.
 *
 * Limitations: the session cookie is sent on "admin_init", so regenerating
 * the session after admin output has started is not supported. With a
 * blocking session driver, the lock is released before the deferred save.
 */
class DeferredStartSession extends StartSession
{
    /**
     * Defer the save to the terminate method.
     *
     * @param \Illuminate\Http\Request $request
     */
    protected function saveSession($request)
    {
    }

    /**
     * Save the session once WordPress has finished the admin request.
     *
     * @param \Illuminate\Http\Request                   $request
     * @param \Symfony\Component\HttpFoundation\Response $response
     */
    public function terminate($request, $response)
    {
        if ($this->sessionConfigured() && $request->hasSession()) {
            parent::saveSession($request);
        }
    }
}
