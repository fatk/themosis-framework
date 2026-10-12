# Admin lifecycle verification and proposed repair

Date: 2026-10-12. Base: `2c12e5d5`, branch `fix/audit-remediation`.
Status: refined design for independent review; **no lifecycle production implementation**. Refinement base: `45b6b919`.

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

## Selected coordinated repair

Three approaches were considered. A second save at shutdown loses early flash and
cannot correct committed cookies; reject it. Subclassing three middleware classes
and copying their protected startup/save/lock algorithms works, but recreates
version-sensitive vendor behavior and loses state of custom container instances.
Select a request-local **deferred middleware unwind**: wrap only supported resolved
middleware objects in PHP Fibers and suspend immediately after their downstream
call returns. Resume the original objects at the final WordPress boundary. This
uses the installed implementations for persistence, serialization, cookies,
encryption and lock release; no session algorithms are reimplemented.

This is one coordinated subsystem. Middleware deferral, owned output capture,
response reconciliation and termination must ship together. No partial production
commit may introduce deferred saves without a working finalizer and cookie boundary.

### Exact middleware compatibility

Add `Themosis\Core\Http\Admin\AdminRequestLifecycle`,
`DeferredMiddleware`, and `AdminResponseBuffer`. Kernel global middleware and the
router's already-expanded, priority-sorted route middleware pass through
`AdminRequestLifecycle::pipes(array): array` only while this request is active.
Outside that scope return the original array. Do not change the container bindings,
router aliases, priority list, stored middleware strings or termination resolution.

Each string is parsed once at the first colon; preserve comma-separated parameters
and resolve its class through the existing container. Route aliases are already
expanded by `gatherRouteMiddleware`; global entries keep the kernel's existing
class-name convention. Closures and supplied object middleware retain normal
pipeline handling. For object middleware, apply the same exact-class eligibility
check without resolving a replacement. Resolve lazily when the pipeline invokes
the pipe, not when constructing the list: this preserves middleware dependency and
exception timing. Invoke the same resolved instance with the original parameters.

Default eligible concrete classes are exactly Illuminate's `StartSession`,
`AddQueuedCookiesToResponse`, and `EncryptCookies`. Container factories returning
these exact types remain authoritative, including configured encrypters, cookie
exception lists and lock cache resolver. Arbitrary subclasses, decorators and
custom replacements are not silently substituted. Expose
`allowDeferredMiddleware(string $concreteClass): void` on the coordinator for
explicit exact-class opt-in to the same suspension contract. There are no existing
framework wrapper subclasses in this checkout; root confirmed the audit group uses
standard StartSession and ShareErrorsFromSession. Do not infer safe subclasses by
reflection or by their names. For a non-opted-in subclass of these three middleware,
report one compatibility diagnostic through the existing exception reporter and
disable deferral for the entire active stack before invoking its original handle.
Every standard adapter checks that request-wide flag again after downstream returns
and before suspending, so all three original unwind operations remain together.
Also disable if a requested standard class binding resolves to an unrelated custom
object. Late persistence is explicitly not guaranteed for that customized stack.
Never defer StartSession while a non-opted-in encryption wrapper unwinds early;
that could expose plaintext cookies. If incompatibility is discovered only after
a Fiber is already queued (nonstandard nested dispatch), fail finalization, drain
queued Fibers for resource release, and suppress all managed cookie publication. Unrelated middleware remains unchanged.

Lazy wrappers preserve the installed Pipeline::carry branches exactly. First test
is_callable on the originally supplied pipe: invoke such a pipe directly and return
its value without Responsable conversion (including callable objects with handle).
For a non-callable string, parse parameters and resolve lazily; for a non-callable
object, retain its identity. Invoke handle when present, otherwise __invoke, with
the original arguments. Only this non-callable branch converts Responsable via
its toResponse(container->make(Illuminate\Http\Request::class)), exactly as routing
handleCarry does. Do not normalize originally supplied closures. The wrapper does
not catch invocation/conversion exceptions: the surrounding original routing
Pipeline catches, reports, renders and applies withException. The same invocation
closure is passed into an eligible adapter, so its final resumed result receives
that branch's conversion too. Unrelated middleware does not acquire a Response
return constraint. Callable customized session/cookie objects still undergo the
compatibility check before invocation; opt-in retains their callable branch.

The adapter starts a Fiber invoking that original invocation closure. Its supplied `$next`
calls the real downstream continuation, appends the current Fiber to the lifecycle
queue, then suspends with the provisional response. It returns the final response
when resumed. The queue is naturally in original response-unwind order: session,
queued cookies, encryption for the standard stack. Do not sort it by class names.
A middleware returning without invoking next completes normally and is not queued.
An opted-in middleware must call next at most once and return a Symfony Response;
multiple next calls, an unexpected suspension, or a non-Response return are reported
contract errors, never silently treated as successful finalization.

The routing pipeline still catches downstream exceptions and produces its usual
error response before the adapter suspends. A startup/acquisition exception occurs
at its original place and is handled by that existing pipeline/kernel. No Fiber
runs inside a PHP output-handler callback. Fiber references remain owned until
finalization, including on native exit after kernel handling.

### Whole-request state and failures

States are `new -> active -> finalizing -> finalized|failed`. `begin(Request,
Kernel): void` is called before admin kernel handling; `stage(Response): void`
retains the framework response and emits its non-cookie headers without flushing
body output. `finish(): Response` is the one terminal operation. Mark finalizing
before invoking any user/vendor callback. A duplicate or reentrant finish returns
the current final response without invoking any deferred operation or termination
again. Initialize finalResponse to the staged response or a new empty Response before setting
finalizing, and before snapshot or any callback can reenter. No failed operation
is retried. Record each Fiber as attempted before resume;
check it is terminated after resume, then accept its returned Response.

At finish, collect captured body and native status/headers into a real Symfony
Response. Copy provisional framework cookies into its managed cookie bag. Resume
queued Fibers once in queue order with this response, accepting each returned
response. The real StartSession performs storeCurrentUrl, identity cookie creation
and exactly one save, in its installed order. Real Store ages flash once, serializes
PHP/JSON and uses EncryptedStore when configured. CookieSessionHandler queues its
payload during save; AddQueuedCookies sees that payload afterward; EncryptCookies
then honors per-instance and static exceptions and prefixes.

On any resume/cookie/preparation exception, report through the configured Themosis
handler and obtain its rendered error Response while headers remain mutable. Clear
all framework-managed cookies from the failed candidate, including cookies added
before a partially completed save. Continue resuming remaining suspended outer
Fibers with the error response so their finally blocks run; if another operation
fails, report it and again clear the candidate cookie bag. Suppress publication of
all managed cookies for the failed finalization, even if outer operations succeed.
Never retry save or manually repair flash. A failed storage write may already have
partially persisted data: error outcome is honest, no rollback is claimed.
`Store::save()` ignores a handler's false return; preserve that vendor behavior and
make no stronger durability claim. Thrown failures are observable.

Mark termination attempted before calling `Kernel::terminate(request, finalResponse)`.
Call it once even after a deferred failure. Preserve its existing abort-on-exception
semantics: do not rerun middleware or application callbacks after a termination
exception. Report that exception; keep the prepared response. Application callbacks
registered by other application termination callbacks continue according to the
existing Application loop. No global change to Application::terminate is needed.
If the reporter or renderer itself throws, use a minimal 500 Response and error_log
as last-resort reporting; still unwind remaining Fibers and attempt termination.

The original StartSession blocking try/finally remains suspended across WordPress
execution. On resume or exception its original finally releases its lock exactly
once. Do not add another release path. Acquisition timeout retains the vendor's
original finally/release semantics. Preserve configured lock TTL and wait time;
this repair does not extend or renew a lock lease. A request outliving its configured
lease cannot claim exclusive access for that extra time. Session regeneration keeps
the originally acquired lock key, exactly as upstream middleware does.

Every started Fiber is owned before start, separately from the ordered deferred
queue. On unexpected initial suspension or suspension after its single normal
resume, mark finalization failed and perform exactly one cancellation injection:
Fiber::throw(new LogicException('Deferred admin middleware cancelled.')). Catch
and report cancellation/cleanup errors, then inspect isTerminated. This injection
is resource cleanup, never a normal resume, retry or second call to handle/save.
A contract-compliant try/finally runs once. If custom code catches cancellation and
suspends again, do not inject or resume again: retain the Fiber in a quarantined
owned list until request teardown, report unresolved cleanup, suppress managed
cookies, and continue draining other queued Fibers and attempting termination.
There is no guarantee that arbitrary custom code releases resources before
teardown, or refrains from writes while catching cancellation. Opt-in requires
propagating cancellation without suspending or repeating persistence; release-on-
error and one-save guarantees apply to installed middleware and compliant opt-ins.
Never claim quarantine as successful cleanup; do not add manual lock release.

Snapshot and header-preparation failures follow the same error policy as resume
failures: retain an initialized error candidate, drain every queued Fiber once,
attempt termination once, restore hooks in finally and notify observers as failed.
Header preparation is separate from publication; neither failure may skip drain.

### WordPress/PHP boundary and output

Installed WordPress registers `shutdown_action_hook` in `wp-settings.php:166`.
That callback runs the entire shutdown action then wp_cache_close
(`wp-includes/load.php:1302`). The default flush-all action has priority 1
(`default-filters.php:443`); `functions.php:5578` blindly calls ob_end_flush once per
initial level. A non-removable buffer is therefore unsuitable: it produces notices
which the application may convert to exceptions.

Use a normal removable owned capture buffer, with a handler that appends chunks to
owned storage and returns the empty string. Register a PHP shutdown finalizer after
WordPress's registered callback. While active, replace only the standard priority-1
`wp_ob_end_flush_all` action with an owned wrapper at priority 1. The wrapper calls
the **actual** WordPress function; in finally it immediately reinstates owned
capture if the lifecycle remains active. Other shutdown hooks are not removed or
skipped. Same-priority callbacks retain their mutual order; the wrapper is appended
at that priority. Capture holds the body while the standard flush drains buffers,
and the reinstated buffer holds output from later callbacks. Finalization happens
after the complete WordPress shutdown callback, before owned bytes are published.
On explicit finish, restore the removed standard hook and remove only the owned
wrapper; tests must restore hook state even on failure. If the standard callback
was absent, do not invent it.

Record the starting buffer level and owned handler identity. Close only removable
buffers above/including that owned buffer to collect their output at finalization;
never explicitly remove a pre-existing buffer. The actual WordPress flush-all may
remove those buffers as it normally does. Unexpected owner removal outside the
wrapper is a boundary violation; do not silently start another lifecycle. The
handler can record its final-close event but cannot finalize, resume Fibers, start
buffers or flush other buffers from inside itself. Use a temporary stream for
captured body to avoid retaining duplicate large strings; close it after publish.

Before resuming, native status, Location and non-cookie headers are authoritative:
construct the final Response from http_response_code and headers_list, with the
captured body. Keep native Set-Cookie lines separate from managed cookies so
WordPress authentication cookies are never accidentally encrypted. Preserve their
raw values and attributes. After successful unwind, replace only native cookie
lines whose name/domain/path tuple matches a managed cookie, then append the final
managed cookies. Retain unrelated native cookies, including same names at other
paths/domains. Identify native cookies from the raw first name=value pair without
URL decoding the name; managed names use their emitted wire spelling. Parse raw
attribute presence separately from Symfony Cookie::fromString. For omitted,
empty, or non-slash Path use browser default-path: take the request URI path (no
query); if it is empty, not slash-prefixed, or contains only its leading slash,
use '/'; otherwise take everything before the rightmost slash. Thus
/cms/wp-admin/tools.php defaults to /cms/wp-admin. Domain omission means the
canonical lowercase request host; explicit Domain is lowercase with leading dot
removed. Host-only and explicit current-host cookies collide by name/domain/path
(the host-only flag is not a separate storage key). Paths and raw names compare
case-sensitively. Invalid/ambiguous names or domain attributes remain raw and are
not considered identified conflicts. Partitioned native cookies also remain raw
unless partition identity is known; this repair does not infer a partition key.
Never use Symfony's default '/' or decoded name alone as native identity. These
identity rules follow [RFC 6265 sections 5.1.4, 5.2.4 and 5.3](https://httpwg.org/specs/rfc6265.html). Never issue an early session identity cookie. Prepare headers, run termination, collect callback output, then set final status
and non-cookie headers, replay reconciled Set-Cookie lines and publish body once.
Always remove provisional Content-Length because captured/termination output can
change it; let the server frame the response. Apply Response::prepare(request)
after the complete body is assembled. HEAD emits zero body bytes; 1xx, 204 and
304 emit no body and retain only headers permitted by Symfony prepare. Do not
append captured callback output after prepare, which would restore forbidden
body bytes. Ordinary GET publishes the assembled body exactly once. Keep capture active during finalization: when
collecting the pre-finalization body, immediately open a fresh owned capture before
resuming middleware or termination. Collect middleware/termination output and append it to the captured response body
before the final prepare(request) call; no callback output may escape ahead of
the headers. Sequence: assemble body, prepare for method/status, publish headers,
invoke diagnostic observers with their output discarded, publish prepared body. The Response passed to terminate represents the body prepared at
that point, before termination-generated output. The response supplied to terminate includes
native status, Location, prepared pre-termination body and the combined cookie headers. Observer callbacks are
diagnostic-only: discard their captured output, report thrown observer errors without
retrying finalization, and never append observer bytes after response preparation. Do not
resend the provisional empty admin body over WordPress output.

### Redirect, error and unsupported boundaries

Both Page GET and POST redirects call lifecycle `send(Response): void` when active:
stage its status/non-cookie headers and emit its body into owned capture with
`sendContent`, then preserve the existing exit. Do not call Response::send() in
this path: Symfony's default send invokes fastcgi_finish_request/litespeed_finish_request
or closes buffers and flushes (`Response.php:405`). Shutdown still runs all regular
WordPress callbacks before final save. Without an active lifecycle, retain the
existing Page send/exit path.

For an early framework >=500 response, stage headers and sendContent into capture,
then return to WordPress exactly as today. Remove the inaccurate 'stop process'
comment; do not add exit or finish FastCGI. Later WordPress callbacks may still
mutate the session and their output remains in the body. Native wp_redirect + exit,
wp_die, normal completion and late thrown errors **after kernel handling has
returned and registered deferred Fibers** share this fallback. An exit inside
downstream middleware/route code before next returns is unsupported: its started
Fiber has not reached the deferral point. At shutdown detect owned nonterminated,
unqueued Fibers, report incomplete kernel handling, suppress managed cookies and
apply bounded cancellation only if suspended. An executing Fiber cannot be
cancelled safely; report unresolved cleanup and make no persistence/resource-release
claim for that path. Termination is still attempted once with the fallback response.
Do not present late native-exit evidence as covering this case. Framework-caught exceptions retain the existing handler-rendered
response. No claim is made for process kill, memory exhaustion or fatal errors
before begin; do not replace WordPress's exception/fatal machinery.

Explicit flush/stream/send-and-finish is a terminal boundary, not a supported
continuation of this stateful admin flow. Public `finish()` permits callers to
finalize before doing an intentional stream/flush; subsequent session mutations
are outside the lifecycle and cannot be persisted a second time. A StreamedResponse
or BinaryFileResponse staged while active produces a reported unsupported-boundary
error rather than pretending its body was captured. If headers are already sent at
begin, report and leave the original middleware behavior in place. If headers become
sent or the owned buffer disappears unexpectedly after activation, report a failed
boundary; resume the one save/unwind and terminate to release resources, but do not
claim cookie delivery, issue header warnings or retry. This includes arbitrary
ob_end_flush/wp_ob_end_flush_all outside the owned shutdown wrapper and external
fastcgi_finish_request calls. Explicit PHP flush may commit headers even if body
capture is empty; it is not covered by normal completion guarantees.

### Evidence and acceptance

Isolated prototypes in `/private/tmp/life-design-*-probe.php` use installed classes
without vendor/config mutation. PHP 8.5.11 results:

- buffer probe loads actual WP plugin.php/functions.php: wp-start, wp-flush,
  late-hook, wp-end, finalize, headers:open; body exactly earlylate.
- Fiber StartSession probe: writes before=0, after=1; same final Response=true;
  status=302; regenerated identity cookie matches; early/late flash both in _flash.old.
- cookie probe: deferred order StartSession, AddQueuedCookies, EncryptCookies;
  real cookie payload contains early+late flash, identity encrypted, configured
  exception cookie remains plain, final status 302.
- lock probe: one acquire, zero releases before late work, one release after finish.
- thrown-save probe: owned-write-failure, one attempted write, one lock release,
  Fiber terminated; the failed save is not resumable/retried.

These are design experiments, not production acceptance. Regular tests must cover
combined early/late flash across three fresh requests; one handler write; late
regenerate-and-redirect identity; all supported exits; exactly-once termination and
reentrancy; save/encryption/termination failure; acquired-lock release on thrown
save; existing binding identity, aliases/parameters, custom opt-in and diagnostics;
PHP/JSON/encrypted stores and real cookie sessions; no-session and complete frontend
controls; native Location/status/unrelated cookie preservation; real WP flush-all
plus later shutdown callback; early 500 continuation; explicit-boundary diagnostics.

Move the owned HTTP fixture's recorder from its independent early-registered PHP
shutdown callback to a callback registered through `afterFinalized(Closure): void`.
Invoke observers after final cookie headers and termination, before body publish,
on both success and failure; observer failure is reported without retrying finalize.
Observe counters only there or from a subsequent request. Root owns real HTTP and
Chrome acceptance, freshly provisioned guarded fixture values, cleanup and full
suite runs. No cache/config/env changes, dependency upgrades, nonce migration,
multisite expansion, push, merge or PR are part of LIFE-01. AUDIT.md stays untouched.
