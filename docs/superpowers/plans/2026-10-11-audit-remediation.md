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

## Later disposition (not authorized in Phase 2)

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
