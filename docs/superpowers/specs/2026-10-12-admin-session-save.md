# Admin session save (LIFE-01)

Status: implemented. Replaces the earlier Fiber-based admin lifecycle design,
which was dropped as too complex for this fork.

## Problem

`Application::manageAdmin()` runs the HTTP kernel on `admin_init`. Illuminate's
`StartSession` saves the session when its middleware returns, but WordPress then
runs page callbacks, `admin_post_*` actions and redirects. Session writes made
there were lost, and the kernel was never terminated. Saving a second time at
shutdown is not a fix: it ages flash data twice and loses the early flash.

## Repair

- `dispatchToAdmin()` extends the container entry for Illuminate's `StartSession`
  with `Themosis\Core\Http\Middleware\DeferredStartSession` for that admin request
  only. Custom middleware bound under that name is left untouched.
- `DeferredStartSession` skips the save while the middleware unwinds and saves once
  in `terminate()`, using the installed vendor save. Flash data therefore ages once
  and both early and late writes persist.
- `dispatchToAdmin()` registers one PHP shutdown function that calls
  `$kernel->terminate($request, $response)`. PHP shutdown functions run after
  WordPress's own `shutdown` action, including after `exit()` from redirects or
  `wp_die()`. Terminable middleware and application terminating callbacks now run
  once for admin requests.
- Frontend `manage()` is unchanged.

## Limitations

- The session cookie is sent on `admin_init`. Regenerating the session after
  admin output has started is not supported.
- With a blocking session driver, the lock is released when the middleware returns,
  before the deferred save.

## Tests

- `tests/Core/AdminLifecycleTest.php`: early and late writes saved by exactly
  one handler write, flash visible on exactly one following request, kernel and
  middleware terminated once, custom `StartSession` bindings not replaced.
- `tests/Integration/WordPress/AdminSessionLifecycleTest.php`: real `admin_init`
  and `admin_post_*` hooks; Page POST writes persist after WordPress finishes.
