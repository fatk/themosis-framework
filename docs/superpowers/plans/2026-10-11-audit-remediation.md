# Audit remediation plan

## Authorized scope and boundaries

The first tranche implemented T1–T4: SEC-01, capability/method checks from
SEC-02, DATA-01 and DATA-04, with repository-owned WordPress integration
coverage. The user subsequently authorized Phase 2, T5–T7, in this conversation.
Save valid submitted metabox fields; preserve invalid and omitted fields.
Explicit empty/null values clear only when validation permits them. Preserve
nonce-free callers: mandatory nonce enforcement remains deferred and SEC-02
remains partially open. Framework user/term forms emit submitted-field markers;
custom forms may continue submitting explicit values without markers.

Preserve CACHE_DRIVER=redis, REDIS_DB=0 and WP_REDIS_DATABASE=1. Do not
replace drop-ins or change application configuration. Restore the date factory
through the current architecture in a later tranche. Verify the complete admin
session lifecycle before prescribing session-save or termination changes.

## Preparation and harness

Start from HEAD 3d3c3d77a7d66deae23991ab92d29d50fe9c2992, preserving all
changes. Create fix/audit-remediation in this checkout (use the first available
dated suffix if occupied). Leave AUDIT.md untracked and untouched.
Baseline: 256 tests / 830 assertions, 288 parsed non-Blade source files,
no locked production Composer advisories or abandoned packages.

Reuse /private/tmp/themosis-audit.dEOTfM/compose.yml. Confirm the vendor
framework symlink and reflected source resolve to /framework. Guard mysql /
themosis_audit / audit_ and fixture host before mutation. Valkey stays on the
audit backend network; never run retained flush probes without checking DB
selection. Restart only audit PHP when OPcache requires it.

Create phpunit.wordpress.xml.dist, tests/Integration/WordPress/bootstrap.php,
WordPressTestCase.php, fixtures/admin.php and README.md. Exclude this directory
from the mock-WordPress unit suite. Require THEMOSIS_WP_BOOTSTRAP explicitly;
the retained fixture is /srv/fixture-bootstrap.php. Own all records, temporary
directories and fixture registrations. Restore hooks, user, request globals,
application bindings and cache state; delete only test-owned resources. Future
drop-in coverage runs in separate processes. Temporarily load the HTTP fixture
only in the audit site, retaining existing fixtures.

```sh
audit_wpunit() {
  docker compose -f /private/tmp/themosis-audit.dEOTfM/compose.yml exec -T \
    -e THEMOSIS_WP_BOOTSTRAP=/srv/fixture-bootstrap.php php php \
    /framework/vendor/bin/phpunit -c /framework/phpunit.wordpress.xml.dist \
    --cache-directory /tmp/themosis-wordpress-phpunit "$@"
}
```

Each task: add regressions, demonstrate intended failures on unfixed code,
implement the smallest fix, then targeted/unit/integration/HTTP checks and full
suites. Setup errors do not count as red regressions.

## T1 — SEC-01: metabox target permissions

Files: src/Metabox/Manager.php, Controllers/MetaboxApiController.php and
Metabox.php; tests/Metabox/MetaboxTest.php, MetaboxApiControllerTest.php and
tests/Integration/WordPress/MetaboxAuthorizationTest.php.
Share target authorization before reads/writes: existing positive post ID,
edit_post for that object, configured metabox capability and matching
post-type/screen/template applicability. Conceal unavailable/unauthorized
targets with 404. Check registration before resolving unknown IDs; preserve
deliberate HTTP exceptions. Normalize unset optional capability/template
getters. Keep existing authentication, throttling and CSRF middleware.
Cover admin/editor/author/subscriber/guest, own/other posts, restricted and
unrestricted boxes, draft/private/published, wrong type/template, invalid IDs,
GET/PUT; denial precedes loaders/savers and leaves DB unchanged. Chrome HTTPS
checks must verify denied status, no restricted value exposure, and API/DB
rereads. Commit: Fix: Enforce metabox target permissions.

## T2 — SEC-02 (partial): Page POST dispatch

Files: src/Page/Page.php, tests/Page/PageTest.php and integration
PageActionAuthorizationTest.php. Check configured capability, then require
POST; authorized wrong methods return 405 with Allow: POST. Use WordPress
denial handling outside Laravel middleware. Preserve signatures, callback
arguments, nonce generation and application safeguards. Subscriber GET/POST
never dispatch; admin GET is 405 and POST dispatches once. Cover nondefault
and network capabilities, anonymous dispatch and callback-owned nonce checks.
Verify HTTP status/headers and synthetic option unchanged on denial.
Commit: Fix: Enforce Page action capability and method checks.
Action-bound nonce enforcement needs a later caller/form migration plan.

## T3 — DATA-01: metabox persistence

Files: src/Metabox/Manager.php and integration MetaboxPersistenceTest.php.
Validate submitted registered fields, use key existence, save only submitted
valid values; do not synthesize null for omitted/invalid fields. Preserve
messages and return stored values for unsaved fields. Keep response shape.
Cover invalid required replacement, mixed valid/invalid, omitted required,
empty payload, replacement, permitted null/empty clear, rejected clear and
zero reaching saver unchanged. Assert metadata_exists as well as value.
HTTP invalid Save retains old DB value while valid sibling updates; reread.
Depends on T1. Commit: Fix: Preserve metadata after failed metabox validation.

## T4 — DATA-04: user/term persistence

Files: src/User/UserField.php, src/Taxonomy/TaxonomyField.php, User main and
Taxonomy add/edit Blade views; tests/User/UserFieldTest.php and integration
UserFieldPersistenceTest.php / TaxonomyFieldPersistenceTest.php.
Collect explicit values and marked omitted checkbox/multiple-choice clears.
Markers are scoped to registered fields and establish submission context,
not authorization. Ordinary omitted fields and invalid fields retain metadata.
Retain WordPress permission/nonce protections. Custom forms need explicit
false/empty array or a marker to clear omitted controls. Cover real
wp_update_user display-name and wp_update_term description updates, unrelated
requests, partial forms, invalid fields, marked control clears, create/update,
unknown markers. Native forms and metadata rereads verify behavior.
Commit: Fix: Preserve custom metadata during unrelated user and term updates.

## Phase 2 — remaining metadata contracts (authorized)

### T5 — DATA-02: preserve zero-valued metadata

Modify src/Forms/Fields/Types/TextType.php, NumberType.php, IntegerType.php,
ChoiceType.php and src/Forms/Transformers/NumberToLocalizedStringTransformer.php.
Replace scalar truthiness checks with explicit empty-value handling; use
metadata existence for create/update and loading zero. Preserve multiple-choice
clear behavior. Add tests/Integration/WordPress/MetadataValueTest.php and extend
tests/Forms/FieldsTest.php. Cover 24 post/user/term zero cases, create/update/read,
locales/precision, valid zero choice and intentional empty controls. Run
./vendor/bin/phpunit tests/Forms/FieldsTest.php and audit_wpunit --filter
MetadataValueTest. Zero keys must exist and roundtrip; permitted clears work.
Commit: Fix: Preserve zero-valued metadata.

### T6 — DATA-03: preserve raw metadata strings

Modify src/Forms/Fields/Types/TextType.php. Keep raw internal strings and apply
wp_slash() exactly once to metadata write values. Preserve retrieved
previous-value comparison. Do not guess whether strings are already slashed
or globally unslash JSON requests. Add integration MetadataEscapingTest.php:
post/user/term create/update, inherited text fields, JSON with escaped quotes,
literal backslashes, decoded JSON HTTP requests and native WordPress forms.
Verify the native form request boundary before adapter normalization. Run
audit_wpunit --filter MetadataEscapingTest. Require byte-for-byte rereads and
valid JSON. Commit: Fix: Preserve raw metadata strings at WordPress write boundaries.

### T7 — DATA-05: validate supplied Page setting values

Modify src/Page/Page.php. Bind registered sanitizers to their specific field,
validate the supplied value under that field name and remove registration-order
and global-POST dependence. Retain stored values on actual validation failure.
Add integration PageSettingsSanitizationTest.php and extend tests/Page/PageTest.php.
Cover empty/conflicting POST, out-of-order/repeated sanitization, programmatic
update_option, new/existing options, valid/invalid input and ordinary forms;
exercise REST when explicitly enabled. Run ./vendor/bin/phpunit tests/Page/PageTest.php
and audit_wpunit --filter PageSettingsSanitizationTest. Valid values persist;
invalid ones retain old values. Commit: Fix: Validate supplied Page setting values.

The existing regression-first, owned-resource, full-suite, HTTP, explicit
staging and audit-project shutdown requirements apply to each Phase 2 task.
Keep all later cache, nonce, lifecycle and other decisions deferred.

## Phase 4 — routing, rendering and editor correctness (authorized)

After Phase 2, the user deferred the entire cache phase and authorized T12–T18.
Keep working on fix/audit-remediation in this checkout. Phase 3's proposal is
unapproved and deferred; no cache/store/environment/drop-in changes are included.

### T12 — SEC-03: respect response cache privacy

Modify src/Route/Middleware/WordPressHeaders.php. Remove guest setPublic()
overriding response policy. Add tests/Route/WordPressHeadersTest.php and
tests/Integration/WordPress/ResponseHeadersTest.php. Preserve private/no-store/
no-cache, Laravel-authenticated WordPress guests and personalized guest sessions;
retain explicit public controls. Run the targeted unit file and audit_wpunit
--filter ResponseHeadersTest. Chrome confirms final HTTPS response headers.
No cache-disclosure exploit is asserted. Commit: Fix: Respect explicit response cache privacy.

### T13 — SEC-04: conditional route constraints

Modify src/Route/Route.php; extend tests/Route/RoutesTest.php and
RouteCollectionTest.php. Conditional routes retain ordinary validators except
the intentionally bypassed URI validator, plus their condition validator.
Cover correct/wrong host, HTTP/HTTPS, true/false conditions, fallback and GET/
POST collection dispatch. Run ./vendor/bin/phpunit --filter
'(RoutesTest|RouteCollectionTest)'. Host/scheme constraints hold and POST to a
GET-only route remains MethodNotAllowed. Commit: Fix: Honor conditional route host and scheme constraints.

### T14 — ROUTE-01: configured named route URLs

Modify src/Core/helpers.php. Add tests/Route/RouteUrlTest.php and integration
MultisiteRouteUrlTest.php. Delegate absolute/relative route generation to the
configured URL generator. Keep rootUrl()'s documented meaning. Cover root,
subdirectory/index.php, forced roots, route domains/placeholders, queries,
absolute=false and retained main/child multisite URLs. Run the targeted unit
file and audit_wpunit --filter MultisiteRouteUrlTest. Chrome checks actual
fixture navigation separately from CLI multisite controls.
Commit: Fix: Preserve named route domains and base paths.

### T15 — VIEW-01: runtime Blade template arguments

Modify src/View/BladeServiceProvider.php; add
tests/View/BladeTemplateDirectiveTest.php. Compile runtime argument evaluation
from the original PHP expression rather than splitting commas or executing
selectors during compilation. Cover compile once/render post and page, static/
dynamic selector, fallback, inherited variables, multiple/nested data keys and
quoted commas. Run the targeted unit file. Reused compiled views must select
current templates and preserve all data. Clear only test-owned compiled views
for HTTP checks. Commit: Fix: Evaluate Blade template arguments while rendering.

### T16 — VIEW-03: stable ordered view paths

Modify src/View/FileViewFinder.php; extend tests/View/FileViewFinderTest.php.
Derive a stable ordered merge without repeatedly modifying base paths;
preserve priority and registration order for ties. Cover repeated path equality/
count, missing/new views, later locations and no-ordered controls. Run the
targeted unit file. Commit: Fix: Keep ordered view paths stable.

### T17 — VIEW-02: finder-backed Twig resolution

After T16, modify src/View/ViewServiceProvider.php, Engines/Twig.php and
src/Core/ThemeManager.php; add src/View/TwigLoader.php, tests/View/TwigLoaderTest.php
and TwigEngineTest.php. Use finder-backed resolution for top-level views,
includes and extends, preserving loader compatibility methods used by theme
registration. Cover duplicate overrides, unique theme files, late locations,
namespaces, aliases, nested templates and cache keys. Run ./vendor/bin/phpunit
--filter '(TwigLoaderTest|TwigEngineTest|FileViewFinderTest)'. Finder and renderer
must select the same source, including after compiled-template reuse.
Commit: Fix: Resolve Twig views through the framework finder.

### T18 — UI-01: editor Code mode persistence

Modify resources/js/src/components/fields/EditorField.tsx, add
resources/js/tests/components/editor-field.test.tsx and regenerate only directly
required dist/js/themosis.core.js. Code textarea changes update serialized
state; Visual edits synchronize without depending solely on blur. Cover Code
edit then immediate save, blur/no-blur, transitions with healthy Visual control
and repeated saves. Install existing locked JS dependencies without upgrades.
Run yarn test --runInBand resources/js/tests/components/editor-field.test.tsx,
yarn test --runInBand and yarn production. Chrome verifies saved values via
API/database and reload. Exclude unrelated generated poststatus output.
Do not add speculative remount, Gutenberg/classic publish-race or TinyMCE
initialization changes. Commit: Fix: Persist metabox editor Code mode changes.

Each task retains the regression-first, full-suite, owned-fixture, atomic-commit,
HTTP verification and final audit-project shutdown requirements below.

## Later disposition and deferred phases

| Task | Finding / intended work |
| --- | --- |
| T8 | CACHE-01/06: forever expiry and genuinely atomic absent-write primitives for each supported backend. |
| T9 | CACHE-02/03: configuration inventory and explicit isolated-store/namespace migration approval required; never global flush migration. |
| T10 | CACHE-04: request-local nonpersistent groups for all operations. |
| T11 | CACHE-05/PERF-01: reset found, one backend read, distinct null without breaking numeric operations. |
| T12 | SEC-03: preserve explicit response cache privacy; remove guest setPublic override. |
| T13 | SEC-04: retain host/scheme validators for conditional routes, bypass URI only. |
| T14 | ROUTE-01: use configured URL generator for route domains/base paths/relative URLs. |
| T15 | VIEW-01: Blade template directive evaluates original PHP arguments at render time. |
| T16 | VIEW-03: stable ordered view paths without repeated mutation. |
| T17 | VIEW-02: finder-backed Twig loader for top-level/includes/extends, after T16. |
| T18 | UI-01: editor Code changes serialize immediately and Visual edits synchronize; targeted/full Jest and production output only. Healthy remount failure remains unestablished. |
| T19 | LIFE-01: design/verify complete admin lifecycle, cookies, flash aging, redirects, errors and exactly-once termination before implementation. |
| T20 | OPS-01: fail fast and propagate optimize child exit codes without all-success output. |
| T21 | API-01: restore legacy date factory with current types/views/React architecture; preserve third-party interfaces. |
| T22 | PERF-02: bounded ID-keyset scheduled-post enumeration, after cache repair. |
| T23 | LOW-01: custom-entrypoint maintenance contract verification first; normal WordPress maintenance already works. |
| T24 | LOW-02: constant guard confirmed, but verify healthy subdomain-network behavior before activating URL branch. |
| T25 | LOW-03: unavailable media thumbnail/file fields return null, strict warning coverage. |

No speculative plugin/WooCommerce/job-chain/publish-race fixes, blanket CSRF
exclusions, conditional method-dispatch fix, native Laravel nested-array fix,
or healthy editor remount fix. Existing subdirectory theme URLs worked.

## Phase 5 authorization — 2026-10-12

Proceed with lifecycle verification/design (T19), optimize failure propagation
(T20), the public date factory (T21), custom-entrypoint maintenance verification
(T23), and unavailable media handling (T25). Skip multisite work (T24) unless
evidence establishes a security threat. Cache-dependent scheduled-post batching
(T22) remains deferred with Phase 3. Preserve the existing local branch and all
audit/environment boundaries.

- **T19:** Add complete admin lifecycle controls under
  `tests/Core/AdminLifecycleTest.php`,
  `tests/Integration/WordPress/AdminSessionLifecycleTest.php`, and owned HTTP
  fixtures. Establish early/late writes, flash aging, redirects/exits,
  regenerated IDs, cookies/final headers, errors, Page POST ordering, and
  terminable callbacks. Review the control/design before production changes.
  Any eventual repair must persist the correct session identity with exactly
  one flash-aging boundary and exactly one termination; saving twice is not
  sufficient. Source: `src/Core/Application.php`, `src/Page/Page.php`.
- **T20:** Update `OptimizeCommand.php` and `OptimizeClearCommand.php` to stop
  at the first failing child, return its nonzero code, identify that child,
  and omit all-success output. Cover every failure position, distinct codes,
  exclusions, success, and thrown exceptions; exercise installed Artisan
  through injected commands without clearing unrelated caches.
- **T21:** Restore `Factory::date($name, array $features = [], array $attributes = [])`
  with `DateType`, facade documentation, native Forms/User/Taxonomy views, and
  a registered React component. Inventory the implementation in a separate
  specification. Preserve feature/attribute mapping and third-party factory
  interfaces. Cover ISO dates, empty values, configured validation failure,
  rendering/submission, and text controls. Run PHP/Jest suites and the
  production build; include only directly required generated output.
- **T23:** Establish normal WordPress and supported custom-kernel maintenance
  behavior with isolated controls before selecting any middleware repair.
  Do not put the retained audit HTTP site into maintenance.
- **T25:** Guard false thumbnails and unavailable local files in
  `MediaFieldTransformer`; preserve keys and return null for unavailable
  thumbnail/size. Cover valid, deleted, missing, offloaded, and nonimage
  resources under strict warning capture and confirm renderer compatibility.

Task briefs and execution reports accompany the review process. No speculative
lifecycle, maintenance, or multisite change is authorized by an unverified
finding; the reviewed control/design gates still apply.

## Verification and handoff

After each coherent fix: targeted tests, ./vendor/bin/phpunit, audit_wpunit,
composer --no-cache audit --locked --no-dev --format=json, git diff --check,
diff and staged name-status, git status --short --branch. Parse all non-Blade
src PHP with token_get_all(..., TOKEN_PARSE). Keep source/test/documentation
inventory scoped; no unrelated formatting, dependencies, generated output or
application configuration. Explicitly stage files. Tests and fixes share
atomic commits using configured identity/signing/trailers, no AI attribution.
No push, merge or PR. Finally stop only the retained audit Compose project,
inspect ps -a, and retain fixtures/volumes.

## Final disposition — 2026-10-12

Work resumed after the previous session stopped mid-LIFE-01. Guiding rule from
the user: choose the simplest fix and never break the sites built on this fork.
The Fiber-based admin lifecycle design was dropped in favor of a deferred
session save (see `docs/superpowers/specs/2026-10-12-admin-session-save.md`).

| Finding | Status | Commit(s) |
| --- | --- | --- |
| SEC-01, DATA-01, DATA-04, DATA-05, SEC-03, SEC-04, VIEW-01, VIEW-03, UI-01, API-01, LOW-03 | Fixed (earlier session) | see `git log` |
| SEC-02 | Fixed: capability/method checks, then cross-site origin guard instead of a nonce migration | 053af8e2, d1fe8059, e7884585 |
| DATA-02/03 | Fixed; textarea/editor follow-up | 2836607b, 5b685ccd, 24f0480d |
| ROUTE-01 | Fixed; admin/AJAX/cron regression follow-up | 05e83f56, 3efe155c |
| VIEW-02 | Fixed; non-view include regression follow-up (would have broken every site) | 824efada, beaf1ab1 |
| OPS-01 | Fixed; optimize:clear runs every task | 0d4b63d0, 6b0c7416 |
| LIFE-01 | Fixed with deferred session save and kernel termination | d5624da6 |
| LOW-01 | Verified only; no supported custom entrypoint established | 6ea0d3d7 |
| CACHE-01..06, PERF-01/02 | Deferred by the user: no site uses the framework drop-in | — |
| LOW-02 | Skipped by the user: multisite unused, no security impact | — |

Issues found after the audit:

| Issue | Status | Commit |
| --- | --- | --- |
| Custom post status bypass of the publish capability | Fixed | ecd9a08d |
| Metabox API / Page action / Form cross-site requests | Fixed | d1fe8059, e7884585 |
| Admin middleware denials did not stop WordPress | Fixed | d51042f4 |
| User/term fields writable without edit capability | Fixed | 5f434fbc |
| Metabox 500 messages leaked; options.php ignored Page capability | Fixed | cad6c45c |
| `salts:generate` crashed | Fixed | 82db3498 |
| `make:auth` generated fataling controllers | Now refuses | 7710a5e1 |
| Asset handle case / external detection | Fixed | ff7d449e |
| Page settings not registered for REST/cron | Not fixed (needs admin/non-admin split) | — |
| Media/collection fields accept any attachment ID | Not fixed (low) | — |

Behavior changes are listed in `CHANGELOG.md`.
