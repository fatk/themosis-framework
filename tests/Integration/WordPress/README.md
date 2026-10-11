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
Use the existing authenticated/CSRF-protected metabox API for browser requests;
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
