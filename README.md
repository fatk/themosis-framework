Themosis Framework 13
=====================

A modernization of the [Themosis framework](https://github.com/themosis/framework) 3.1 (WordPress framework built on Laravel components) to **Laravel 13**, **PHP 8.4/8.5** and **WordPress 7**.

> [!WARNING]
> **This fork is not meant to be stable or maintained for third-party users.**
> It is a sane modernization of the work of the original author, kept up to date for a handful of existing projects.
> There is no support, no backward compatibility promise and it may change at any time.
> Issues and pull requests are not monitored.

Versioning
----------

The major version follows the Laravel major version: **13.x requires Laravel 13** (`illuminate/*` `^13.35`).
The [`12.x`](https://github.com/fatk/themosis-framework/tree/12.x) branch keeps Laravel 12 (`^12.0`) for fixes.

Requirements
------------

- PHP `^8.3` (used on PHP 8.4 and 8.5; Symfony 8 components need PHP 8.4+, PHP 8.3 gets Symfony 7.4)
- WordPress 6.8+ (used on WordPress 7.1)
- Composer 2

Installation
------------

Add the repository and require the package in an existing Themosis application:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/fatk/themosis-framework" }
    ],
    "require": {
        "themosis/framework": "^13.0"
    }
}
```

The [original documentation](https://framework.themosis.com) still applies to the framework APIs (routes, post types, taxonomies, metaboxes, fields, forms, pages, hooks...).

Changes from Themosis 3.1
-------------------------

### Dependencies

| Package                    | Themosis 3.1 | Themosis 13          |
|----------------------------|--------------|----------------------|
| PHP                        | `^8.0`       | `^8.3`               |
| `illuminate/*`             | `^8.83`      | `^13.35`             |
| Symfony components         | `^5.4`       | `^7.4` / `^8.0`      |
| `league/flysystem`         | `^1.1`       | `^3.29`              |
| `laravel/ui`               | `^3.1`       | `^4.0`               |
| `laravel/tinker`           | `^2.5`       | `^3.0`               |
| `composer/installers`      | `^1.9`       | `^2`                 |
| `predis/predis`            | `^1.1`       | `^2.3` / `^3.0`      |
| `symfony/property-access`  | `^5.4`       | `^7.4` / `^8.0`      |
| `paragonie/sodium_compat`  | `^1.15`      | `^1.24.2` / `^2.5.2` |
| `guzzlehttp/guzzle`        | `^7.2`       | `^7.8.2` / `^8.0`    |
| `league/fractal`           | `^0.20`      | `^0.20` / `^0.21`    |
| PHPUnit (dev)              | `^9`         | `^12.5` / `^13.0`    |

### Major fixes

- **Laravel 13 port** (13.0): `PreventRequestForgery` CSRF middleware (`VerifyCsrfToken` kept as a deprecated subclass), exception handler retry/reporting API (`dontRetry()`, `shouldStopRetries()`, `isReporting()`), console `findCommand()`, domain-first route matching, `optimize --except`, Laravel 13 versions of `PendingChain` and the event `Dispatchable`.
- **Laravel 9 → 12 port**: container, contracts and helpers aligned with Laravel 12 (`Application::register()` force flag, `publicPath()`, `useStoragePath()`, `maintenanceMode()`, fallback locale, terminating callbacks, removed `symfony/debug`, `SerializableClosure` and `DetectsApplicationNamespace` usages).
- **Errors & deprecations**: PHP warnings and notices are logged instead of rendering an error page; deprecations go to a dedicated log channel (on when `WP_DEBUG` is enabled, off otherwise) and no longer depend on the `error_reporting()` mask; PHP 8.4+ deprecations fixed.
- **Console**: `migrate*` and `schedule:*` commands restored, closure commands and `#[AsCommand]` commands fixed, `vendor:publish` works with Flysystem 3, `make:auth` fixed.
- **Queues**: queued closures, closure chains, `Bus::chain()`, `dispatchAfterResponse()` (terminating callbacks) and scheduled closures work with standalone `illuminate/*` components.
- **Production caches**: `config:cache` keeps `.env` available to WordPress configuration and themes, `route:cache` no longer drops theme routes, `optimize` / `optimize:clear` cover config, events, routes and views.
- **Service providers**: Metabox, Page and Composer providers are deferred again (`DeferrableProvider`).
- **Laravel parity**: exception handler `ignore()` / `stopIgnoring()` / `level()`, `FormRequest::validated($key, $default)`, automatic policy registration, `current_password` never flashed.

### Additions

- `opcache:clear`: resets the **PHP-FPM** OPcache from the CLI through FastCGI (`--fcgi=host:port|/path/to.sock`, or `OPCACHE_FCGI`, default `127.0.0.1:9000`).

Production
----------

After a deployment:

```bash
php artisan optimize        # config, events, routes and views
php artisan opcache:clear   # reset the PHP-FPM OPcache last
```

Theme routes and theme views are registered when WordPress loads the theme: they are not part of the route and view caches and keep loading at runtime.

Deprecations logging (`config/logging.php`):

```php
'deprecations' => [
    'channel' => env('LOG_DEPRECATIONS_CHANNEL'), // null: follows WP_DEBUG, false: disabled
    'trace' => env('LOG_DEPRECATIONS_TRACE', false),
],
```

Upgrading from 12.x
-------------------

- Require `"themosis/framework": "^13.0"` and PHP 8.3+, then `composer update`.
- Run `composer deploy` (or `php artisan optimize`) again: route and config caches built with Laravel 12 must be regenerated.
- CSRF: `Themosis\Core\Http\Middleware\VerifyCsrfToken` now extends `PreventRequestForgery`. Where the middleware is enabled, same-origin requests (`Sec-Fetch-Site: same-origin`) pass without a token, and the encrypted `X-XSRF-TOKEN` header is now accepted.
- Unauthenticated web requests without a redirect target get an empty 401 instead of a redirect to `route('login')`.
- Laravel 13's new cache/Redis prefixes, session cookie name, `cache.serializable_classes` and JSON session serialization only come with its skeleton config files: existing `config/*.php` files keep their values. Also check pagination view names (`pagination::default` → `pagination::bootstrap-3`) and `upsert()` with an empty `uniqueBy`. See the [Laravel 13 upgrade guide](https://laravel.com/docs/13.x/upgrade).
- The `errors.php` mu-plugin that masked `E_DEPRECATED` is no longer needed: deprecations go to the `deprecations` channel (`WP_DEBUG` on) or are discarded, and are never displayed.

Credits
-------

- **[Julien Lambé](https://github.com/jlambe)**, author of the Themosis framework, and the [Themosis contributors](https://github.com/themosis/framework/graphs/contributors).
- Some fixes are ported from other community forks: [digitalmeat](https://github.com/digitalmeat/themosis-framework), [MyronS-Leobit](https://github.com/MyronS-Leobit/themosis-framework), [AmphiBee](https://github.com/AmphiBee/framework), [brand42](https://github.com/brand42/themosis-framework).

License
-------

GPL-2.0-or-later, see [LICENSE.md](LICENSE.md).
