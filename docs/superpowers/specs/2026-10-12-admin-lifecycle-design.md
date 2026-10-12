# Admin lifecycle verification and proposed repair

Date: 2026-10-12. Base: `2c12e5d5`, branch `fix/audit-remediation`.
Status: verification/design gate; **no lifecycle production implementation**.

## Reproduced request boundary

`Application::manageAdmin()` registers `dispatchToAdmin()` on `admin_init`.
The real HTTP kernel dispatches `AdminRoute`, whose empty response traverses the
`admin` middleware group. Illuminate `StartSession::handleStatefulRequest()` starts
the session, executes the route, adds its current identity cookie, then saves.
`Store::save()` ages flash data and writes the session, and marks it unstarted.
`dispatchToAdmin()` sends headers (or the body for status >= 500) and returns to
WordPress. Neither this method nor the admin registration calls kernel termination.

WordPress subsequently invokes `load-*` page callbacks and `admin_post_*` actions.
`Page::parsePostRoute()` registers the latter on `admin_init`; its callback runs
after the kernel/session boundary. Page redirects call `send()` and `exit()`.
Normal WordPress output, native redirects/exits, and Page redirects therefore
outlive the framework middleware response. Late mutations change the in-memory
store, but do not reach its handler. A late `regenerate(true)` additionally destroys
the original stored identity after its cookie has already been selected.

Source anchors at this base: `src/Core/Application.php:1552` (registration),
`:1572` (early response), `:1497` (application callbacks),
`src/Core/Http/Kernel.php:193` (kernel termination),
`src/Route/AdminRoute.php:34` (catch-all admin route),
`src/Page/Page.php:936` (GET redirect), `:970` (POST dispatch/redirect),
`vendor/illuminate/session/Middleware/StartSession.php:109` (save/cookie unwind),
`vendor/illuminate/session/Store.php:181` (save), `:233` (flash aging),
`vendor/illuminate/cookie/Middleware/AddQueuedCookiesToResponse.php:34` and
`vendor/illuminate/cookie/Middleware/EncryptCookies.php:72` (cookie unwind).

## Healthy controls and defect evidence

All values and users were freshly owned. No retained IDs or pre-existing session
contents were prerequisites. Unit requests construct a fresh application, router,
kernel, session manager and store while retaining only their owned storage handler.
WordPress tests use actual action queues and restore hooks, container, request and
user state. The HTTP probe derives ownership from the freshly provisioned admin
fixture option and uses a unique cookie and temporary file-session directory.
The audit application's effective session driver was already `file`; only the
request-local cookie/path and garbage collection lottery are changed by the probe.
The actual admin middleware group is retained and a recording middleware appended.
The probe never adds a save or a terminate call.

| Request sequence | Observed result |
| --- | --- |
| Complete framework requests, early write, read, read | Normal persists; flash visible on one following request only; cookie matches store; middleware and application terminate once |
| Regenerate inside the complete middleware request | Old identity destroyed; new issued cookie retrieves the new session |
| Real WordPress `admin_init`, then Page POST action | Session middleware runs before Page callback; early value is durable and available to the callback |
| Chrome early write, read, read (200) | Normal persists; flash visible once, then absent |
| Chrome late write, read (200) | Late values exist in memory; stored value and next request retain the preceding early control value |
| Chrome late regenerate, read (200) | In-memory identity changes; old file removed; new identity has no file; next request uses original identity and loses values |
| Chrome WordPress redirect (302 → 200) | Late normal and flash values absent on destination |
| Chrome rendered Page form POST (`admin-post.php`, 302 → 200) | Callback executes after middleware; destination does not receive its normal or flash value |
| Chrome early error response (500 → read 200) | Early normal and flash persist and can be read |
| All 15 observed admin HTTP requests | Application and middleware termination counters both zero; exactly one owned session-cookie header per response |
| Explicit second-save counterexample | Late flash becomes durable but early flash is deleted within the same request |

The browser evidence was gathered by the root agent using Chrome and isolated CLI
record inspection; its local evidence note is `/private/tmp/audit-t19-browser-evidence.md`.
Cookie identity after regeneration was verified by comparing the next request's
session identity, not merely assuming the in-memory ID was issued to the browser.
Neither the healthy controls nor the diagnostic are a claim that LIFE-01 is fixed.

## Repeatable verification

Healthy PHPUnit controls:

```sh
vendor/bin/phpunit tests/Core/AdminLifecycleTest.php
docker compose -f /private/tmp/themosis-audit.dEOTfM/compose.yml exec -T -e THEMOSIS_WP_BOOTSTRAP=/srv/fixture-bootstrap.php php php /framework/vendor/bin/phpunit -c /framework/phpunit.wordpress.xml.dist --cache-directory /tmp/themosis-wordpress-phpunit --filter AdminSessionLifecycleTest
```

Results: 3 tests / 17 assertions and 2 tests / 13 assertions, both pass.
Full host suite: 313 tests / 1022 assertions. Full isolated WordPress suite:
287 tests / 2668 assertions. Production locked dependency audit reports no
advisories or abandoned packages. PHP parsing covers 294 source/new fixture/test
files (Blade templates excluded), with no failures. The required full WordPress
suite includes its pre-existing multisite CLI controls; no new multisite work or
browser acceptance was performed.

Explicit acceptance diagnostic (intentionally outside PHPUnit discovery):

```sh
php tests/Integration/WordPress/fixtures/reproduce-admin-lifecycle.php
```

Observed exit **1**. Early flash control is true; late normal persistence, late
flash persistence, exact application termination, exact middleware termination,
and late regenerated cookie/store identity checks are false. The accompanying
counterexample reports early flash lost after a second save. This is an explicit
failing design-gate diagnostic, not a skipped test or a green regression claim.
The eventual fix must turn these acceptance assertions green and incorporate
whole-request regression coverage into the regular suite.

HTTP reproduction: explicitly load `tests/Integration/WordPress/fixtures/admin-lifecycle.php`
after the existing audit admin fixture, with a newly owned administrator signed in.
Visit `/cms/wp-admin/tools.php?page=themosis-lifecycle&lifecycle_case=early&lifecycle_run=XXXXXXXXXXXXXXXX`,
substituting fresh 16-character lowercase hexadecimal runs. Follow the read link
twice. Repeat with `late`, `regenerate`, `redirect`, and `error`; submit the rendered
Page POST form. Inspect `themosis_owned_lifecycle_records()` after each request;
it records event order, memory/storage values, session identities, headers/status,
and termination counts. Run `themosis_owned_lifecycle_cleanup()` **before** cleaning
the parent admin fixture; it removes only guarded owner-directory session files
and the owner record option. Remove the temporary mu-plugin loader separately.

## Proposed minimal repair architecture (requires review)

A termination-only change in `Application` cannot satisfy persistence. A second
`Store::save()` cannot satisfy flash lifetime. A shutdown-only cookie update cannot
repair headers already sent. The minimal coherent unit therefore extends beyond
adding calls to `Application.php` and `Page.php`:

1. Introduce an admin request lifecycle coordinator, activated by `manageAdmin`
   before kernel handling. It retains the request, kernel, response, deferred
   response callbacks, and independent `finalizing`, `persisted`, and `terminated`
   guards. It owns only output buffering it starts, recording the original level.
2. Add an admin-aware `StartSession` adapter. Retain normal startup, garbage
   collection and request session binding. In an active admin lifecycle defer
   `storeCurrentUrl`, session-cookie construction, and `saveSession` to the final
   response boundary. Outside that context delegate unchanged to Illuminate.
   Invoke the real store's `save()` **once**, preserving serialization and handler
   semantics, so `_flash.new` becomes `_flash.old` exactly once per request.
3. Defer the response side of queued-cookie/encrypted-cookie middleware too when
   present. Run deferred response operations in their original unwind order:
   session finalization, queued cookies, then cookie encryption. Use the actual
   configured cookie policies and encryption exception lists. Do not manually
   serialize stores, copy flash internals, or issue plaintext replacement cookies.
4. Keep WordPress output in the coordinator's owned buffer until a final boundary.
   For normal/native-exit requests, finalization occurs after WordPress shutdown
   hooks and before PHP implicitly flushes output. Preserve native response status,
   `Location`, and unrelated cookies/headers when reconciling framework cookies.
   Avoid resending the empty admin response over WordPress content.
5. `Page` redirect paths explicitly finalize the actual redirect response before
   `send()`/`exit()` can flush or finish FastCGI. Framework error responses likewise
   finalize before sending. Whether >=500 must stop all subsequent WordPress
   processing needs an explicit behavioral decision: today's source comment says
   stop, but it does not exit. Do not silently bundle that behavior change.
6. Terminate the kernel exactly once after final response preparation/persistence;
   the PHP shutdown fallback handles native exits, and explicit Page/error paths
   share the same guard. Mark termination in progress before calling callbacks to
   avoid reentrant duplication. Preserve middleware termination and callbacks
   registered during application termination. Never globally redefine
   `Application::terminate()` as once-only for unrelated uses.

Recommended compatibility boundary: map the exact standard Illuminate middleware
classes to these admin-aware adapters **only while resolving the admin request**.
Keep non-admin routes unchanged. Existing custom subclasses or container bindings
must retain their behavior; provide explicit adapter opt-in rather than silently
replacing them. The exact resolver integration and custom middleware contract must
be reviewed before implementation. This is preferable to a global session-manager
or Store override, which would alter unrelated framework behavior and drivers.

This proposal is a concrete repair direction, not approval to start implementing.
The next change must first prove final-boundary mechanics with failing regressions
and settle the compatibility points below. No source changes are in this commit.

## Remaining uncertainty and next acceptance gate

- Cookie-backed sessions, encrypted cookies, custom session middleware/bindings,
  JSON/encrypted stores, and lock-enabled sessions are not exercised by the audit
  HTTP fixture. Blocking-session lock lifetime needs special care when deferring
  persistence; do not release locks at the original early middleware boundary.
- Explicit `flush()`, arbitrary buffer manipulation, streamed responses, native
  headers sent before the framework hook, and fatal errors before initialization
  cannot be treated as covered by the normal/redirect/error controls.
- The error control creates an early 500 response; it does not establish thrown
  exception behavior at every WordPress stage or process-kill durability.
- The current HTTP recorder is registered on `init`. A future shutdown finalizer
  registered later could run after it. Move acceptance observation after actual
  finalization (or use a subsequent request/storage reader) before using its
  counters to validate a future implementation. Current zero termination findings
  are also independently confirmed by source and the explicit kernel diagnostic.
- For a future change, assert early and late flash together, regenerate-and-redirect
  identity, exactly one handler write/aging boundary, final headers, and exactly one
  termination for normal, native redirect/exit, Page GET and POST redirects, and
  errors. Verify no-session and complete front-end request controls as well.
- Multisite is excluded by user instruction. Cache/configuration/environment,
  dependency upgrades, nonce policy and retained fixtures are outside this work.

The design gate remains open until the coordinator/adapters and compatibility
boundary are reviewed. Full suite success here validates the added controls; it
does not close LIFE-01.
