# Maintenance entrypoint verification (T23)

## Decision

Keep production behavior unchanged. The native WordPress maintenance path is
verified. A supported custom entrypoint that bypasses WordPress bootstrap and
promises framework maintenance enforcement has not been established. The middleware
TODO alone does not establish a security bypass or justify a production fix.

## Observed native behavior

The retained audit site's `htdocs/index.php` loads `cms/wp-blog-header.php`.
Installed WordPress `wp-settings.php` calls `wp_maintenance()` early (line 79),
before ordinary plugin/theme processing. `wp_maintenance()` consults
`wp_is_maintenance_mode()` and terminates an active request. WordPress's own
content maintenance handler is supported and also ends the request.

Run the repository-owned diagnostic against the installed WordPress source:

```sh
docker compose -f /private/tmp/themosis-audit.dEOTfM/compose.yml exec -T php php /framework/tests/fixtures/maintenance-entrypoint-probe.php /srv/htdocs/cms
```

Each control uses a fresh PHP subprocess, the installed `plugin.php` and
`load.php`, and an owned temporary ABSPATH/content directory. The handler emits
`native-maintenance`; downstream code emits `downstream`. This exercises the
real early maintenance functions without a database or the retained site's
`.maintenance`. The diagnostic does not test the default translated 503 page or
claim to reproduce a complete HTTP bootstrap.

| Control | Result |
| --- | --- |
| No maintenance file | Inactive; downstream runs |
| Fresh integer timestamp | Active; native handler runs; downstream does not run |
| Timestamp older than 600 seconds | Inactive; downstream runs |
| Installation in progress | Inactive; downstream runs |
| `enable_maintenance_mode` returns false | Inactive; downstream runs |
| `WordPressMaintenanceMode::activate(['retry' => 60])` | Active; payload round trips; native handler terminates |
| Framework deactivation | Inactive; empty payload; owned files removed |

The framework writes `$upgrading = time()` as PHP, so active framework maintenance
does not expire merely because the file ages. Native WordPress's age test applies
to a fixed old timestamp. The framework's `active()` tests file presence; this is
different from native WordPress's timestamp/installing/filter policy.

## Custom entrypoint evidence and remaining questions

`PreventRequestsDuringMaintenance::handle()` checks `isDownForMaintenance()` but
its active branch contains a TODO and invokes the next callable. This is a direct
source observation, not proof of a reachable supported entrypoint.

The repository's base `Core/Http/Kernel.php` has an empty global middleware stack.
A repository-wide search finds no middleware registration referring to
`PreventRequestsDuringMaintenance`. `Application::handle()` delegates to an
HTTP kernel; `manage()` is documented for theme integration; `manageAdmin()`
dispatches through `admin_init`. Those APIs do not by themselves specify a
WordPress-free deployment or promise maintenance enforcement before bootstrap.
README/docs/test entrypoint searches did not establish that deployment contract.

Before implementation, establish an actual supported custom entrypoint and its
bootstrap sequence, middleware registration, expected maintenance response,
timestamp/filter/installing semantics, and intentional bypass policy. Obtain
review of that concrete design. Then add a failing regression through that
entrypoint and implement the smallest agreed change. Do not label the present
observation a security bypass, wire middleware speculatively, or change native
WordPress behavior based on a synthetic kernel diagnostic.

## Scope and restoration

Only this verification specification and a standalone reproduction fixture are
added. No production code, environment, dependencies, cache, drop-ins, HTTP site
maintenance files, or global WordPress hooks are changed. Hooks live only in
short-lived child processes. Fixture files are removed in the owning parent's
`finally` block, including when a child fails.
