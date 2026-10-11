# WordPress integration tests

These tests use real WordPress, an explicitly supplied bootstrap, and only the
isolated audit database (`mysql` / `themosis_audit` / `audit_`) at
`https://themosis-audit.local.dev/cms`. The bootstrap fails closed elsewhere.
Do not load the unit-test WordPress mocks in this process.

```sh
docker compose -f /private/tmp/themosis-audit.dEOTfM/compose.yml exec -T \
  -e THEMOSIS_WP_BOOTSTRAP=/srv/fixture-bootstrap.php php php \
  /framework/vendor/bin/phpunit -c /framework/phpunit.wordpress.xml.dist \
  --cache-directory /tmp/themosis-wordpress-phpunit
```

`WordPressTestCase` creates unique owned records and deletes those records in
teardown. It restores hooks, request globals, current user, current screen and
the application container. Tests must unregister their own container bindings,
track terms as `[id, taxonomy]` and options by key, and restore any additional
bindings/cache entries they modify. Never flush shared cache databases. Drop-in
coverage must use separate processes. Confirm `/srv/vendor/themosis/framework`
resolves to `/framework` and reflected framework classes load from `/framework/src`.

## Owned HTTP fixture

Keep retained audit fixtures intact. Temporarily add an audit-only mu plugin
that requires `/framework/tests/Integration/WordPress/fixtures/admin.php` only
for HTTP requests (CLI commands load it after WordPress finishes bootstrap):

```php
<?php
if (PHP_SAPI !== 'cli') {
    require '/framework/tests/Integration/WordPress/fixtures/admin.php';
}
```

The fixture refuses any other database/site and stays inactive until setup.
Run this CLI command with `THEMOSIS_HTTP_FIXTURE=setup`, `inspect`, or `cleanup`:

```sh
docker compose -f /private/tmp/themosis-audit.dEOTfM/compose.yml exec -T \
  -e THEMOSIS_HTTP_FIXTURE=setup php php -r \
  'require "/srv/fixture-bootstrap.php"; require_once "/framework/tests/Integration/WordPress/fixtures/admin.php";'
```

Setup returns unique synthetic role credentials and owned author/admin draft
IDs. HTTP metabox IDs are `themosis_regression_public` (required `th_note` plus
`th_sibling`) and `themosis_regression_private` (`th_secret`, `manage_options`).
Use the existing authenticated metabox API for browser requests;
inspect metadata through the CLI command. Denied GET/PUT must be 404, reveal no
restricted value and leave DB values unchanged. After checks run cleanup and
remove only the temporary loader. Credentials exist solely on the isolated
audit site. Cleanup verifies ownership before deleting each record.

The owned Page action is `themosis_regression_page_save` at `admin-post.php`.
Subscriber GET/POST return 403; administrator GET returns 405 with `Allow: POST`;
administrator POST returns JSON and increments `page_action_count` in `inspect`.
This callback accepts nonce-free POST to verify existing caller compatibility.
Guest requests retain WordPress's missing unauthenticated-handler response.
Cleanup also removes the owner-specific counter option.

For native user/category form checks, `THEMOSIS_HTTP_FIXTURE=setup-fields`
extends the existing owned fixture with seeded `th_profile_note`,
`th_profile_flag`, `th_profile_choices` user metadata and an owned category
with `th_term_note`, `th_term_flag`, `th_term_choices`. It returns owned IDs
without credentials. Open `user-edit.php?user_id=<owned user>` and
`term.php?taxonomy=category&tag_ID=<owned term>&post_type=post`, save selected
controls, then uncheck the flag and both expanded choices and submit the native
Update button. `inspect` reports the metadata; cleanup owns the new term too.
Add-user and add-category forms also emit field markers. Markers such as
`_themosis_fields[th_profile_flag]=1` identify submitted controls and do not
replace WordPress permissions or nonces. Custom forms may send explicit false
or an empty array without markers; ordinary omitted fields retain metadata.

The same owned fixture exposes zero regression fields for text, number, integer
and single choice (valid options `0` and `7`). The metabox ID is
`themosis_regression_zero`, with `th_zero_text`, `th_zero_number`,
`th_zero_integer`, and `th_zero_choice`. Native user/category forms use
`th_profile_zero_*` and `th_term_zero_*`. Submit `0`, reload, and verify the keys
exist with value `"0"` in `inspect`; replace with `7` and verify the update.
Empty scalar controls intentionally remove their metadata keys.

The owned settings page is `admin.php?page=themosis_regression_settings`. Its
option names are `<owner>_settings_note` (required, minimum five characters) and
`<owner>_settings_count` (required integer, minimum ten). Submit valid values
through the native options.php form, reload and inspect `settings`; then submit
an empty note and count `2` and verify both stored values remain unchanged and
validation errors appear. Cleanup removes only these owner-specific options.
Programmatic updates, empty/conflicting POST, repeated/out-of-order callbacks,
new/existing options and explicitly enabled REST settings are covered by
`PageSettingsSanitizationTest`.

For response privacy acceptance, the active owned fixture exposes
`https://themosis-audit.local.dev/cms/?themosis_response_policy=<case>`.
Guest cases are `private`, `no-store`, `no-cache`, `session`, `laravel-auth`, and
`public`. Inspect the final HTTPS `Cache-Control` header: private controls must
retain `private`, no-store/no-cache directives must remain, and only the explicit
public case advertises `public, max-age=300`. The session uses an in-memory owned
cart and Laravel authentication uses a synthetic request resolver while WordPress
remains a guest; neither modifies authentication/session lifecycle. No cache
server disclosure is asserted. The endpoint becomes inactive after owned cleanup.
