<?php

/** Opt-in isolated HTTP probe. Load after admin.php from an audit-only mu plugin. */
if (! defined('ABSPATH') || DB_HOST !== 'mysql' || DB_NAME !== 'themosis_audit'
    || $GLOBALS['wpdb']->prefix !== 'audit_' || get_option('siteurl') !== 'https://themosis-audit.local.dev/cms') {
    throw new RuntimeException('Lifecycle fixture requires the isolated audit site.');
}

class ThemosisOwnedLifecycleMiddleware
{
    public function handle($request, Closure $next)
    {
        $trace = &$GLOBALS['themosis_owned_lifecycle_trace'];
        $session = $request->session();
        $trace['before'] = ['id' => $session->getId(), 'normal' => $session->get('normal'), 'flash' => $session->get('notice')];
        $trace['events'][] = 'middleware';
        if ($trace['case'] === 'early' || $trace['case'] === 'error') {
            $session->put('normal', $trace['run']);
            $session->flash('notice', $trace['run']);
        }
        $response = $next($request);
        if ($trace['case'] === 'error') { $response->setStatusCode(500); $response->setContent('Owned lifecycle error'); }
        $trace['response_status'] = $response->getStatusCode();
        return $response;
    }

    public function terminate($request, $response)
    {
        $GLOBALS['themosis_owned_lifecycle_trace']['middleware_terminations']++;
    }
}

function themosis_owned_lifecycle_late(): void
{
    $trace = &$GLOBALS['themosis_owned_lifecycle_trace'];
    $trace['events'][] = 'late-wordpress';
    if (in_array($trace['case'], ['late', 'regenerate', 'redirect', 'page-post'], true)) {
        $session = app('request')->session();
        $session->put('normal', $trace['run']);
        $session->flash('notice', $trace['run']);
        if ($trace['case'] === 'regenerate') { $session->regenerate(true); }
    }
}

function themosis_owned_lifecycle_records(): array
{
    $state = get_option('themosis_owned_http_fixture');
    return $state ? get_option($state['owner'] . '_lifecycle_records', []) : [];
}

function themosis_owned_lifecycle_cleanup(): void
{
    $state = get_option('themosis_owned_http_fixture');
    if (! $state || ! preg_match('/^themosis_http_[a-f0-9]{12}$/D', $state['owner'])) { return; }
    $directory = sys_get_temp_dir() . '/' . $state['owner'] . '_lifecycle';
    if (is_link($directory)) { throw new RuntimeException('Refusing linked lifecycle directory.'); }
    if (is_dir($directory)) {
        foreach (glob($directory . '/*') as $file) {
            if (is_link($file) || ! is_file($file) || ! preg_match('/^[a-zA-Z0-9]{40}$/D', basename($file))) {
                throw new RuntimeException('Unexpected owned lifecycle file.');
            }
            unlink($file);
        }
        rmdir($directory);
    }
    delete_option($state['owner'] . '_lifecycle_records');
}

add_action('init', function () {
    $state = get_option('themosis_owned_http_fixture');
    $case = $_REQUEST['lifecycle_case'] ?? '';
    $run = $_REQUEST['lifecycle_run'] ?? '';
    if (! $state || ! current_user_can('manage_options') || ! preg_match('/^[a-f0-9]{16}$/D', $run)
        || ! in_array($case, ['read', 'early', 'late', 'regenerate', 'redirect', 'page-post', 'error'], true)) { return; }
    $directory = sys_get_temp_dir() . '/' . $state['owner'] . '_lifecycle';
    if (is_link($directory)) { throw new RuntimeException('Refusing linked lifecycle directory.'); }
    if (! is_dir($directory)) { mkdir($directory, 0700); }
    $GLOBALS['themosis_owned_lifecycle_trace'] = [
        'run' => $run, 'case' => $case, 'events' => [], 'middleware_terminations' => 0, 'app_terminations' => 0,
        'original_driver' => config('session.driver'), 'cookie' => $state['owner'] . '_lifecycle',
    ];
    // Request-local isolation only; preserves the application's admin middleware order.
    config(['session.driver' => 'file', 'session.files' => $directory,
        'session.cookie' => $state['owner'] . '_lifecycle', 'session.lottery' => [0, 100]]);
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    app('router')->pushMiddlewareToGroup('admin', ThemosisOwnedLifecycleMiddleware::class);
    app()->terminating(function () { $GLOBALS['themosis_owned_lifecycle_trace']['app_terminations']++; });
    register_shutdown_function(function () use ($state, $directory) {
        $trace = $GLOBALS['themosis_owned_lifecycle_trace'];
        $request = app('request');
        $trace['headers'] = headers_list();
        $trace['final_status'] = http_response_code();
        if ($request->hasSession()) {
            $session = $request->session();
            $trace['final'] = ['id' => $session->getId(), 'normal' => $session->get('normal'), 'flash' => $session->get('notice'),
                'new_flash' => $session->get('_flash.new'), 'old_flash' => $session->get('_flash.old')];
            $file = $directory . '/' . $session->getId();
            $trace['stored'] = is_file($file) ? unserialize(file_get_contents($file), ['allowed_classes' => false]) : null;
        }
        $records = get_option($state['owner'] . '_lifecycle_records', []);
        $records[] = $trace;
        update_option($state['owner'] . '_lifecycle_records', $records, false);
    });
    $destination = add_query_arg(['page' => 'themosis-lifecycle', 'lifecycle_case' => 'read', 'lifecycle_run' => $run], admin_url('tools.php'));
    add_action('admin_menu', function () use ($destination) {
        add_management_page('Lifecycle probe', 'Lifecycle probe', 'manage_options', 'themosis-lifecycle', function () use ($destination) {
            echo '<pre id="lifecycle-result">' . esc_html(json_encode($GLOBALS['themosis_owned_lifecycle_trace'], JSON_PRETTY_PRINT)) . '</pre>';
            echo '<a href="' . esc_url($destination) . '">Read next request</a>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="themosis_lifecycle_post_save"><input type="hidden" name="lifecycle_case" value="page-post"><input type="hidden" name="lifecycle_run" value="' . esc_attr($GLOBALS['themosis_owned_lifecycle_trace']['run']) . '"><button>Page POST redirect</button></form>';
        });
        add_action('load-tools_page_themosis-lifecycle', function () use ($destination) {
            themosis_owned_lifecycle_late();
            if ($GLOBALS['themosis_owned_lifecycle_trace']['case'] === 'redirect') { wp_safe_redirect($destination); exit; }
        });
    });
    app('page')->make('themosis_lifecycle_post', 'Lifecycle POST')->route('save', function () use ($destination) {
        themosis_owned_lifecycle_late();
        return new \Illuminate\Http\RedirectResponse($destination);
    }, 'post');
}, 6);
