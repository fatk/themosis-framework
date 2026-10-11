<?php

// Native multisite constants must be loaded in a fresh PHP process.
require dirname(__DIR__, 4) . '/vendor/autoload.php';
$bootstrap = dirname(getenv('THEMOSIS_WP_BOOTSTRAP')) . '/multisite-bootstrap.php';
if (! is_file($bootstrap) || ! in_array($argv[1] ?? '', ['main', 'child'], true)) {
    throw new RuntimeException('Requires the retained isolated multisite bootstrap and main/child mode.');
}
require $bootstrap;
if (! is_multisite() || DB_HOST !== 'mysql' || DB_NAME !== 'themosis_audit' || $wpdb->base_prefix !== 'audit_ms_') {
    throw new RuntimeException('Requires isolated native audit_ms_ multisite.');
}
$site = get_site();
$home = get_home_url();
$request = \Illuminate\Http\Request::create($home . '/probe', 'GET', [], [], [], [
    'SCRIPT_NAME' => rtrim($site->path, '/') . '/index.php',
    'PHP_SELF' => rtrim($site->path, '/') . '/index.php',
    'SCRIPT_FILENAME' => '/srv/multisite-entry/index.php',
]);
$container = new \Illuminate\Container\Container();
\Illuminate\Container\Container::setInstance($container);
$routes = new \Illuminate\Routing\RouteCollection();
$routes->add(new \Illuminate\Routing\Route('GET', 'probe/{id}', ['as' => 'audit.route', 'uses' => fn () => 'probe']));
$routes->add((new \Illuminate\Routing\Route('GET', 'probe/{id}', ['as' => 'audit.domain', 'uses' => fn () => 'probe']))->domain('{tenant}.mapped.example'));
$url = new \Illuminate\Routing\UrlGenerator($routes, $request);
$container->instance('request', $request);
$container->instance('url', $url);
$result = ['mode' => $argv[1], 'site_path' => $site->path, 'home' => $home,
    'expected' => rtrim($home, '/') . '/probe/12?q=yes',
    'actual' => route('audit.route', ['id' => 12, 'q' => 'yes']),
    'relative' => route('audit.route', ['id' => 12, 'q' => 'yes'], false),
    'root' => rootUrl('probe'),
    'domain' => route('audit.domain', ['tenant' => 'alpha', 'id' => 12]),
];
$url->forceRootUrl(get_site_url());
$result['forced_expected'] = rtrim(get_site_url(), '/') . '/probe/12';
$result['forced_actual'] = route('audit.route', ['id' => 12]);
echo json_encode($result, JSON_THROW_ON_ERROR);
