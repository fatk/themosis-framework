# Changelog

## Unreleased (13.x): audit remediation

Hardening of the framework against the findings of the October 2026 audit.
Most changes are pure fixes; the entries under **Behavior changes** can be
visible to applications and should be checked before updating a site.

### Behavior changes

- **Cache-Control:** WordPress guest responses are no longer forced to
  `public`. Responses without an explicit policy keep Symfony's default
  (`no-cache, private`), and explicit `private`/`no-store` policies are respected.
  Check CDN/page-cache rules that relied on the old header.
- **`route()`:** absolute URLs are built from `home_url()` (unless a root is
  forced or the route has a domain). They no longer contain the request base,
  such as `/cms/wp-admin/admin.php`, when generated from wp-admin, admin-ajax or cron.
- **Deploy commands:** `optimize` stops and returns the exit code of the first
  failing child command. `optimize:clear` runs every task and then returns the
  first failure.
- **Cross-site requests:** Page `admin_post` actions, metabox API writes and
  `Form::handleRequest()` reject requests a browser reports as cross-site (or
  same-site from another subdomain). Metabox writes require a real `PUT`.
  Requests without `Sec-Fetch-Site`/`Origin` headers are still accepted.
- **Page actions:** `admin_post` callbacks require the Page capability and
  POST (403/405 otherwise).
- **User/term fields:** saved only when the current user can `edit_user` /
  `edit_term` for the target. Anonymous registrations no longer write custom
  user meta.
- **Admin middleware:** redirects and 401/403/419/429 responses from the
  `admin` middleware group are sent in full and stop WordPress.
- **Admin sessions:** the session is saved once, after WordPress finishes the
  admin request (including page callbacks and redirects), and the kernel is
  terminated. Regenerating the session after admin output has started is
  not supported.
- **Twig:** compiled templates are only re-checked against their sources
  when `app.debug` is on (override with `view.twig_auto_reload`). Deploys must
  clear compiled views (`optimize` / `view:clear` already do). Absolute template
  paths are only accepted inside view locations.
- **`wp.can` routes:** cross-site writes (non-GET/HEAD/OPTIONS) return 403.
- **`make:auth`:** refuses to run; use `laravel/ui` (`php artisan ui:auth`).
- **`salts:generate`:** works again. Salts no longer contain `$`.

### Fixes

- Metabox API: target post/metabox capability checks, preservation of invalid
  or omitted fields, generic 500 messages unless `app.debug`.
- Metadata: zero values and raw strings (JSON, backslashes) persist for text,
  textarea, editor, number, integer and choice fields. Unrelated user/term
  updates no longer erase metadata.
- Page settings validate the supplied value. `options.php` saving honors the Page capability.
- Custom post statuses: only registered statuses the user can publish are applied.
- Conditional routes honor host and scheme constraints.
- Twig template lookups are memoized per finder revision (no per-include
  file probing or exceptions).
- CSRF middleware checks the real HTTP method. An opaque `Origin: null` counts as
  cross-site. The environment can no longer be set through `argv` on web requests.
- Views: Blade `@template` arguments evaluate at render time. View paths stay
  stable. Twig resolves through the framework finder, including non-view
  includes such as `{% include 'svg/icon.svg' %}`.
- Metabox editor Code-mode edits are saved.
- `Field::date()` works again.
- Missing media files and thumbnails are handled.
- Assets: attributes apply to mixed-case handles. Local paths containing
  `http` are no longer treated as external URLs.

### Not addressed

- The optional framework object-cache drop-in (`WordPressCacheWrapper`) keeps
  its audited defects (zero expiry, store-wide flush, key collisions,
  non-persistent groups, non-atomic add). Do not enable it; use a maintained
  object-cache plugin instead.
- Multisite-only issues (`SUBDOMAIN_INSTALL` guard) and the custom-entrypoint
  maintenance middleware TODO.
- Page settings are registered on `admin_init` only, so `showInRest()` has no effect.
