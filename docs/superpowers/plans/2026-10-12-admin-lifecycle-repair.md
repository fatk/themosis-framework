# Admin Lifecycle Repair Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist the complete supported WordPress admin request once, publish its final session identity and cookies, and terminate the HTTP kernel once.

**Architecture:** A request-scoped coordinator captures WordPress output and resumes the original session/cookie middleware response sides after WordPress shutdown. Exact resolved middleware objects execute in suspended PHP Fibers, preserving installed store, cookie, encryption and lock behavior. The standard WordPress flush-all callback runs inside a capture/restart wrapper; Page redirects and early errors avoid premature FastCGI finish.

**Tech Stack:** PHP ^8.3, installed Illuminate 13.35 components, Symfony HttpFoundation, installed WordPress, PHPUnit, existing guarded HTTP/Chrome fixture.

## Global Constraints

- Same checkout and `fix/audit-remediation` branch; do not create a worktree.
- No production changes until independent review of this plan and its companion spec.
- No cache/config/env changes, dependency upgrades, nonce migration, multisite expansion, push, merge or PR are part of LIFE-01. AUDIT.md stays untouched.
- Use installed middleware/store methods; no second save, flash internals repair, plaintext replacement cookie, or manual session serialization.
- Complete frontend request handling stays unchanged.
- Arbitrary custom middleware requires explicit exact-class opt-in; incompatible stacks retain coherent original middleware unwind and emit a diagnostic.
- Preserve early >=500 WordPress continuation; do not introduce an exit policy.
- All production wiring is one coordinated task and commit. Inert support classes may be reviewed independently before activation.
- Tests use owned values and paths; root owns Docker, HTTP/Chrome fixture provisioning, guard checks and cleanup.
- Commits use configured identity/signing, explicit paths, no attribution trailers.

Read [the complete policy/spec](../specs/2026-10-12-admin-lifecycle-design.md) before implementation. The spec's failure, native-header, custom-stack and unsupported-boundary policies are normative.

## File and interface inventory

Create under `src/Core/Http/Admin/`:

- `DeferredMiddleware.php`: original-object Fiber adapter, no container rebinding.
- `AdminRequestLifecycle.php`: request state, pipe resolution, deferred queue, final response, failure reporting, termination, observers.
- `AdminResponseBuffer.php`: owned capture stream/buffer, WordPress flush wrapper, native response snapshot, cookie reconciliation and publication.

Modify `src/Core/Application.php` for begin/stage/error body handling;
`src/Core/Http/Kernel.php` for global pipe adaptation;
`src/Route/Router.php` for route-pipeline adaptation after normal alias expansion and priority sort;
`src/Page/Page.php` for both active-lifecycle redirects.

Tests: extend `tests/Core/AdminLifecycleTest.php`,
`tests/Support/AdminLifecycleHarness.php`,
`tests/Integration/WordPress/AdminSessionLifecycleTest.php`;
create `tests/Core/AdminDeferredMiddlewareTest.php`,
`tests/Core/AdminResponseBufferTest.php` and
`tests/Integration/WordPress/fixtures/admin-lifecycle-process.php` for isolated process boundaries.
Modify existing `fixtures/admin-lifecycle.php` and `fixtures/reproduce-admin-lifecycle.php` only to observe completed finalization and assert the repaired behavior.

Public interfaces (Illuminate Request; Symfony Response; Illuminate HTTP Kernel contract):

```php
AdminRequestLifecycle::__construct(Application $app, AdminResponseBuffer $buffer);
AdminRequestLifecycle::begin(Request $request, Kernel $kernel): void;
AdminRequestLifecycle::active(): bool;
AdminRequestLifecycle::pipes(array $middleware): array;
AdminRequestLifecycle::allowDeferredMiddleware(string $concreteClass): void;
AdminRequestLifecycle::canDefer(): bool;
AdminRequestLifecycle::disableDeferral(string $reason): void;
AdminRequestLifecycle::defer(Fiber $fiber): void;
AdminRequestLifecycle::stage(Response $response): void;
AdminRequestLifecycle::send(Response $response): void;
AdminRequestLifecycle::finish(): Response;
AdminRequestLifecycle::afterFinalized(Closure $observer): void;
DeferredMiddleware::__construct(object $middleware, AdminRequestLifecycle $lifecycle, array $parameters = [], ?Closure $invoke = null);
DeferredMiddleware::handle(Request $request, Closure $next): Response;
AdminResponseBuffer::begin(): void;
AdminResponseBuffer::stage(Response $response): void;
AdminResponseBuffer::snapshot(Response $provisional): Response;
AdminResponseBuffer::publishHeaders(Response $response, bool $managedCookiesAllowed): void;
AdminResponseBuffer::publishBody(Response $response): void;
AdminResponseBuffer::intact(): bool;
AdminResponseBuffer::restoreHooks(): void;
```

`afterFinalized` observers receive `(Response $response, bool $failed)` and run once after header preparation and kernel termination, before body publication. No success observer is implied by failed=true. `active()` is false after failed/finalized, but duplicate finish still returns the stored final Response. The app stores a single coordinator instance for this admin request; no global/static request state.

## Task 1: Inert lifecycle support with real middleware tests

**Files:** Create the three Admin classes and two Core test files listed above. Extend harness only for explicit finalization and counting owned handlers; do not activate Application/Kernel/Router/Page yet.

**Consumes:** Existing exception handler, real StartSession, cookie middleware, Symfony Response, WordPress hook API when present. Installed source anchors: StartSession handleStatefulRequest and handleRequestWhileBlocking; Store::save; CookieSessionHandler::write; EncryptCookies::handle; Symfony Response::send/closeOutputBuffers; WordPress functions.php:5578, default-filters.php:443, load.php:1302.

**Produces:** All interfaces above, inert until begin and pipes are called.

- [ ] **Step 1: Add a failing real-session test.** Use the existing fresh-app harness and a counting ArraySessionHandler subclass. Instantiate the coordinator explicitly and adapt the existing standard middleware instance. The test's essential assertions are:

```php
$handler = new class(120) extends ArraySessionHandler {
    public int $writes = 0;
    public function write($id, $data): bool {
        $this->writes++;
        return parent::write($id, $data);
    }
};
$h = new AdminLifecycleHarness($handler, null);
$lifecycle = new AdminRequestLifecycle($h->app, new AdminResponseBuffer());
$lifecycle->begin($h->request, $h->kernel);
$pipe = new DeferredMiddleware($h->app->make(StartSession::class), $lifecycle);
$response = $pipe->handle($h->request, function ($request) {
    $request->session()->flash('early', 'early-owned');
    return new Response();
});
$lifecycle->stage($response);
self::assertSame(0, $handler->writes);
$h->request->session()->flash('late', 'late-owned');
$h->request->session()->regenerate(true);
$final = $lifecycle->finish();
self::assertSame(1, $handler->writes);
self::assertSame($h->request->session()->getId(), $final->headers->getCookies()[0]->getValue());
$data = AdminLifecycleHarness::stored($handler, $h->request->session()->getId());
self::assertSame(['early', 'late'], $data['_flash']['old']);
self::assertSame('early-owned', $data['early']);
self::assertSame('late-owned', $data['late']);
self::assertSame($final, $lifecycle->finish());
self::assertSame(1, $handler->writes);
```

Run tests in isolated processes where real buffer/header publication would disturb PHPUnit's own buffers. Ordinary tests inject a test buffer implementation overriding publication/snapshot; use real subprocesses for native behavior. Test buffer methods retain the same interface; production requires no test-only flags.

- [ ] **Step 2: Run `vendor/bin/phpunit tests/Core/AdminDeferredMiddlewareTest.php`.** Expected failure: new class absent, then behavioral failures until implemented.

- [ ] **Step 3: Implement the exact-object suspension primitive.** The following skeleton shows normal deferral; the ownership/cancellation and invocation rules immediately below are required parts of this method:

```php
public function handle(Request $request, Closure $next): Response
{
    $calls = 0;
    $fiber = new Fiber(function () use ($request, $next, &$calls) {
        return ($this->invoke)($request, function ($request) use ($next, &$calls) {
            if (++$calls !== 1) {
                throw new LogicException('Deferred admin middleware called next more than once.');
            }
            $response = $next($request);
            if (! $this->lifecycle->canDefer()) {
                return $response;
            }
            $this->lifecycle->defer(Fiber::getCurrent());
            return Fiber::suspend($response);
        });
    });
    $this->lifecycle->own($fiber); // internal: retain before start, including unqueued Fibers
    $response = $fiber->start();
    if ($fiber->isTerminated()) {
        $response = $fiber->getReturn();
    }
    if (! $response instanceof Response) {
        throw new LogicException('Deferred admin middleware must return a response.');
    }
    return $response;
}
```

Add the missing contract guard: each Fiber that suspends must be registered by `defer` exactly once; reject arbitrary middleware Fiber::suspend calls. `defer` records identity with `spl_object_id`; duplicate registration fails. Keep a registered-Fiber identity accessor private/internal to the adapter/coordinator implementation, rather than exposing an application API.

Implement lazy pipe closures with the installed carry branches exactly: test is_callable on the original pipe first, and preserve its direct return without Responsable conversion. Otherwise resolve strings/parameters lazily, retain supplied objects, invoke handle if present or the object as callable otherwise, then convert only this branch's Responsable through toResponse(container->make(Request::class)). Pass that invocation/conversion closure to eligible adapters; the constructor's null invoke default builds the same non-callable handle/invokable closure for direct standard-object tests. No unrelated pipe gains a Response constraint. Keep the outer installed routing Pipeline exception handling, including report/render/withException; do not catch in the lazy closure. Compare exact get_class against defaults plus opted-in classes. Detect known middleware subclasses and standard-name bindings returning replacement objects before invoking them; disable the entire stack and report once. Standard wrappers recheck canDefer after downstream returns. This prevents a custom EncryptCookies subclass from encrypting early while a standard session later emits plaintext cookies. Preserve supplied closures unchanged; callable objects retain callable-before-handle semantics while still receiving the compatibility check and exact-class opt-in rule. Do not change underlying middleware lists, because termination must keep its existing resolution semantics.

Maintain an internal own(Fiber): void registry independent of defer; own before start. An initial suspension without defer or any suspension after normal resume triggers one cancellation throw, never another resume. Catch its error and check termination. If it suspends again, quarantine its reference through request teardown, report unresolved cleanup, mark failed and suppress managed cookies; do not loop or manually release vendor locks. Remove references only on termination. See spec for the deliberately limited guarantee for cancellation-catching custom code. Tests must count try/finally release for initial and post-resume violations and demonstrate bounded quarantine for a cancellation-catching fixture.

- [ ] **Step 4: Implement the coordinator state machine exactly as specified.** Use an ordered list of `{fiber, attempted}` records; mark attempted before resume. Keep `state`, `terminationAttempted`, `finalResponse`, `failed`, `deferralEnabled` and observer list. `finish` initializes finalResponse before any callback, sets finalizing, obtains native snapshot, resumes each Fiber once, checks termination and Response return, prepares reconciled headers, marks termination attempted then invokes kernel terminate, collects callback output and prepares body for request/status, publishes final headers, invokes observers once, discards observer output as diagnostic-only, publishes body, restores owned hooks, and sets terminal state in finally. Each resume failure reports/renders, clears framework-managed cookies and continues outer resumes. A failing reporter/renderer falls back to error_log and a plain 500 Response. All managed cookie publication is disabled after any failed resume, even if later encryption succeeds. Existing vendor finally handles acquired locks; never add a second release.

Snapshot/header-preparation exceptions must still drain every queued Fiber and attempt termination; use the initialized fallback response when snapshot fails. Test finish reentry from snapshot before any snapshot result exists. Do not catch a failed save and resume that Fiber again. Do not throw into a completed Fiber. Reentrant finish returns finalResponse as currently prepared. Termination failures report and stop that kernel's existing termination loop; do not artificially continue application callbacks skipped by a throwing middleware.

- [ ] **Step 5: Implement buffer cooperation and native reconciliation.** `begin` opens an owned `php://temp` stream and removable capture handler returning ''. Preserve the original shutdown flush action's presence and priority. Remove only that callback, insert a wrapper which calls actual wp_ob_end_flush_all and reinstates capture in finally. Register finish after WordPress's PHP shutdown callback through the coordinator. No finalization/Fiber switch occurs inside the output handler. `snapshot` drains only owned/above-owned buffers, immediately restarts capture, reads captured body and native headers/status, copies provisional managed cookies, and returns a Symfony Response. Protect later callback output until publishBody.

Keep raw native Set-Cookie lines outside the managed cookie bag during encryption. Parse raw name and attribute presence separately from Symfony Cookie::fromString: compare wire names without decoding, case-sensitive effective paths, and lowercase domains without leading dot. Omitted/empty/invalid non-slash Path uses request-path browser default directory; absent Domain uses request host and collides with explicit current-host Domain. Follow the companion spec's exact algorithm; retain ambiguous domain/name and unknown partition identities raw. Preserve the exact raw line for non-conflicts; never reconstruct unrelated authentication cookies. Expiration commas do not split cookies: one headers_list element is one Set-Cookie line. For a malformed line, retain raw native content and do not guess its tuple. Managed tuple wins only an identified conflict. Publish raw native lines followed by managed lines, once, with header replacement false; remove the previous Set-Cookie header list first only while headers remain mutable. The response supplied to terminate includes combined cookies, without re-encrypting native ones.

Remove Content-Length unconditionally; assemble termination output before Response::prepare(request), and never append bytes afterward. Test GET changed body with stale Content-Length, HEAD and 204/304 with callback output. Test duplicate same-name/different-path/domain cookies, Path omitted/empty/non-slash/root/admin-directory, omitted versus explicit current-host Domain, distinct raw names such as a%62 and ab, expired cookies with comma-containing Expires, Secure/HttpOnly/SameSite/Partitioned attributes, native Location/status, and a native managed-tuple conflict. Use a PHP HTTP process for actual headers_list/header behavior because CLI does not expose equivalent response headers.

- [ ] **Step 6: Add the failure/compatibility matrix before wiring.** Use real middleware and real stores with owned fake handler/cache-lock endpoints. Required assertions:

| Test case | Exact outcome |
| --- | --- |
| String invokable / callable object with handle / non-callable Responsable | original invocation selected, parameters retained, conversion only in original non-callable branch |
| Exception in resolution/invocation/toResponse | original routing report/render/withException path retained |
| Unexpected initial / post-resume suspend | one cancellation, finally release=1, writes=0 / writes=1, no retry |
| Cancellation caught then suspended | one injection only, failed quarantine, no claimed release; remaining queue and termination attempted |
| Snapshot/header-preparation throw | all queued Fibers drained, one termination attempt, failed observer |
| Snapshot calls finish reentrantly | initialized Response returned; no duplicate work |
| CookieSessionHandler + queue + encryption | payload queued after one save, identity/payload encrypted once, exception cookie plain |
| JSON Store and EncryptedStore | follow-up real store reads both flashes; handler payload follows configured format |
| Blocking session | acquire=1, release=0 during late work, release=1 after finish |
| Throwing handler write | write attempts=1, release=1, failed observer, no managed cookie publication |
| Throwing encrypt | no plaintext cookie, no second save, termination attempted once |
| Reentrant finish from save/termination/observer | no repeated write/termination/observer |
| Reporter/render failure | plain 500 fallback, outer Fibers drained, lock released |
| Termination callback throws | reported once; existing callback-abort behavior preserved |
| Callback adds application termination callback | existing Application while-loop executes it once |
| Bound standard object | same object and its configured exceptions/cache resolver used |
| Aliased route entry with parameters | exact parameters reach opted-in handle, normal priority retained |
| Unopted custom encrypt/session subclass or replacement binding | whole stack unwinds originally, diagnostic once, no plaintext deferred cookie |
| Explicit opt-in custom subclass | original object handle executes; delayed response side once |
| No session driver / skipped middleware | no save/cookie creation; kernel termination once |
| Acquisition timeout | existing exception pipeline response and original lock-finally semantics |

For each case add the assertion first, run the focused test and observe failure before implementing its handling. Do not emulate CookieSessionHandler or encryption by hand. Read prototypes only as experiment references, not production dependencies.

- [ ] **Step 7: Run both new Core test files and existing `tests/Core/AdminLifecycleTest.php`.** New primitive tests pass; existing application controls remain unchanged because support is inert. Inspect `git diff --check`. Commit only new classes/tests and intended harness changes with `git commit -m "feat: add deferred admin lifecycle primitives"` then obtain independent task review and commit any review fixes before activation.

## Task 2: Activate the complete admin boundary and regular regressions

**Files:** Modify Application, Kernel, Router, Page, existing harness/Core/WordPress tests and both existing fixtures; create the process fixture from inventory.

**Consumes:** Task 1 interfaces and policies, real WordPress hooks, existing fresh handler and fixture ownership guard.

**Produces:** A fully coordinated admin lifecycle; frontend and incompatible custom stacks retain their documented behavior. No partial wiring commit.

- [ ] **Step 1: Promote the failing acceptance into the regular suite.** Change the early-admin control to explicitly call finish after the late phase. Add late+early flash in one request, read twice with fresh apps, one handler write, native redirect/regeneration and termination assertions. Use the same owned handler across three fresh request instances; do not call Store::save in the test. Expected first failure: late values absent and termination counters zero with original wiring.

```php
$first->dispatchAdmin();
$first->request->session()->flash('late', $value);
$first->request->session()->put('normal', $value);
$first->finishAdmin();
self::assertSame(1, $first->terminations);
self::assertSame(1, $first->probe->terminations);
$second = new AdminLifecycleHarness($handler, $first->cookieId());
$second->dispatchAdmin();
self::assertSame($value, $second->probe->before['early']);
self::assertSame($value, $second->probe->before['late']);
$second->finishAdmin();
$third = new AdminLifecycleHarness($handler, $second->cookieId());
$third->dispatchAdmin();
self::assertArrayNotHasKey('early', $third->probe->before);
self::assertArrayNotHasKey('late', $third->probe->before);
$third->finishAdmin();
```

The first harness constructor callback flashes `early` to `$value`. `finishAdmin(): void` resolves the bound coordinator and assigns its finish response to harness response. Update cookieId to read that final response. Every in-process WordPress test finishes its owned request before restoring hooks/container.

- [ ] **Step 2: Wire all production boundaries together.** In dispatchToAdmin create/bind the request coordinator (or resolve an explicitly provided instance), begin before kernel handle, stage its returned response, and only for >=500 call sendContent into capture. Kernel wraps only its active request's global list with pipes. Router overrides runRouteWithinStack using installed parent algorithm verbatim except wrapping the already-gathered middleware list with pipes when active; retain shouldSkipMiddleware and prepareResponse. Do not alter gatherRouteMiddleware, so terminable middleware names remain original.

Both Page redirect branches use the same active guard:

```php
if ($app->bound(AdminRequestLifecycle::class)
    && ($lifecycle = $app->make(AdminRequestLifecycle::class))->active()) {
    $lifecycle->send($response);
} else {
    $response->send();
}
exit();
```

Resolve `$app` from the Page's existing container access convention; do not change callback signatures. `send` stages cookies/status/headers and calls sendContent without flush. Normal early admin response stages non-cookie headers only. Early >=500 uses the same stage plus sendContent and returns, without exit and without default send(). Register one PHP shutdown fallback per coordinator and make manually finished fallback a no-op through state guards.

- [ ] **Step 3: Add real shutdown subprocess tests.** The new process fixture loads the existing WordPress bootstrap supplied through THEMOSIS_WP_BOOTSTRAP, constructs only owned request/session state, executes actual admin_init, installs a late shutdown callback after priority1, then ends normally or with native redirect/exit. It writes a uniquely owned JSON observation afterFinalized; parent reads it only after child exit. Assert event order includes actual wp_ob_end_flush_all, late shutdown hook, one write, kernel/app termination, observer; body bytes exactly once. Cover Page GET/POST redirect and early500 callback continuation through guarded existing fixture entry points. Never let a fixture exit kill PHPUnit itself.

Add an exit inside downstream middleware before next returns: failed unqueued-owned-Fiber diagnostic, no successful persistence/resource claim, bounded cancellation only when suspended. Add a stream/explicit flush/owner-removal case: failed diagnostic, no header warnings or cookie-success claim, one save attempt and resource release. Add headers-already-sent-at-begin: coherent original middleware behavior plus diagnostic. An intentional finish before flush has exactly one finalization and no second save after any subsequent mutation.

- [ ] **Step 4: Move HTTP observation.** Register existing owned recorder with afterFinalized once the coordinator exists; do not use its prior early PHP shutdown timing. Keep all ownership/path guards. Record final response status/header/cookie identity plus storage, counts and event order. The standalone reproduce diagnostic now exercises finish rather than adding a manual save and exits zero only when late normal/flash, combined early flash, regeneration identity and exactly-once termination all pass. Retain the second-save counterexample as an explanatory regression control if it remains useful, but do not use it as a repair.

- [ ] **Step 5: Run focused tests.**

```sh
vendor/bin/phpunit tests/Core/AdminLifecycleTest.php
vendor/bin/phpunit tests/Core/AdminDeferredMiddlewareTest.php
vendor/bin/phpunit tests/Core/AdminResponseBufferTest.php
php tests/Integration/WordPress/fixtures/reproduce-admin-lifecycle.php
docker compose -f /private/tmp/themosis-audit.dEOTfM/compose.yml exec -T -e THEMOSIS_WP_BOOTSTRAP=/srv/fixture-bootstrap.php php php /framework/vendor/bin/phpunit -c /framework/phpunit.wordpress.xml.dist --cache-directory /tmp/themosis-wordpress-phpunit --filter AdminSessionLifecycleTest
```

Expected all exit 0. Existing complete frontend controls stay green. Ask root to coordinate Docker commands if provisioning is in progress; do not mutate fixture configuration independently.

- [ ] **Step 6: Independent review and one activation commit.** Review actual diff for every matrix row, coherent custom-stack fallback, native cookie isolation, no early send(true), no second save and no buffer ownership violation. Run git diff --check. Explicitly stage the inventory paths changed by this task; commit `fix: finalize sessions at the WordPress admin boundary` using configured identity/signing. No unrelated files or AUDIT.md.

## Task 3: Whole-request acceptance and final evidence

**Files:** Update the companion spec's evidence section and, only if regressions expose defects, the exact lifecycle files/tests responsible. Root owns browser operations and evidence files.

**Consumes:** Completed Task 2 and fresh guarded administrator/fixture ownership.

**Produces:** Verified LIFE-01 result or a precise remaining failure; no unsupported boundary presented as passing.

- [ ] **Step 1: Root runs normal/late/combined-flash/late-regenerate/native-redirect/Page-GET/Page-POST/error sequences in Chrome.** Follow each write with two reads; confirm final cookie retrieves the stored regenerated identity. Include unrelated native cookie and Location/status control. Read observations after actual finalization only. Preserve existing retained IDs and fixtures.
- [ ] **Step 2: Run full suites and parsing through established commands.** Root uses existing host PHPUnit, complete isolated WordPress suite and Jest commands already verified for this checkout. Parse changed source/test PHP (exclude Blade templates). Repeat only failed/relevant checks after fixes; use baseline 332 PHP /1197 assertions, 291 WP/2721, 17 Jest as context, not an assertion that new totals must match.
- [ ] **Step 3: Independent final review.** Check source against the spec, verify failed-save no-retry and encrypted cookie safety, and compare real native headers/body to observations. Record exact commands, counts, source commit and browser cases in the root evidence report.
Maintain an internal own(Fiber): void registry independent of defer; own before start. An initial suspension without defer or any suspension after normal resume triggers one cancellation throw, never another resume. Catch its error and check termination. If it suspends again, quarantine its reference through request teardown, report unresolved cleanup, mark failed and suppress managed cookies; do not loop or manually release vendor locks. Remove references only on termination. See spec for the deliberately limited guarantee for cancellation-catching custom code. Tests must count try/finally release for initial and post-resume violations and demonstrate bounded quarantine for a cancellation-catching fixture.

- [ ] **Step 4: Root cleans only freshly owned lifecycle files/options/loader before parent fixture cleanup and commits the final evidence update.** No push/merge/PR. State any remaining unsupported boundaries explicitly in final report.

## Design experiment evidence (not implementation acceptance)

`php /private/tmp/life-design-buffer-probe.php`: real installed WP flush-all, later hook, then finalize; headers open; body earlylate.
`php /private/tmp/life-design-fiber-probe.php`: 0 early writes, 1 final write, both flashes aged once, regenerated cookie correct, final Response identity retained.
`php /private/tmp/life-design-cookie-probe.php`: real cookie handler payload saved/queued/encrypted in correct order, exception cookie plain.
`php /private/tmp/life-design-lock-probe.php`: acquired once, held during late work, released once.
`php /private/tmp/life-design-failure-probe.php`: thrown write attempted once, lock released once, Fiber terminated.

These probes are uniquely owned temporary files. They do not modify vendor, production configuration, retained sessions or the application fixture. Native HTTP headers/FastCGI still require Task 2/3 evidence; CLI success is not a substitute.
