<?php

// Opt-in HTTP control. Load after fixtures/admin.php from the owned audit mu plugin.
if (! defined('ABSPATH') || DB_HOST !== 'mysql' || DB_NAME !== 'themosis_audit'
    || $GLOBALS['wpdb']->prefix !== 'audit_' || get_option('siteurl') !== 'https://themosis-audit.local.dev/cms') {
    throw new RuntimeException('Editor fixture requires the isolated WordPress audit site.');
}

function themosis_owned_editor_state(): ?array
{
    $state = get_option('themosis_owned_http_fixture');
    if (! is_array($state) || ! preg_match('/^themosis_http_[a-f0-9]{12}$/D', $state['owner'] ?? '')) {
        return null;
    }

    return $state;
}

function themosis_owned_editor_post(int $id): bool
{
    $state = themosis_owned_editor_state();

    return $state && in_array($id, array_map('intval', $state['posts']), true)
        && get_post_meta($id, '_themosis_http_owner', true) === $state['owner'];
}

add_action('init', function () {
    if (! themosis_owned_editor_state()) {
        return;
    }
    \Themosis\Support\Facades\Metabox::make('themosis_regression_editor')
        ->setTitle('Themosis regression editor')
        ->add(\Themosis\Support\Facades\Field::editor('regression_editor', ['label' => 'Regression editor']))
        ->set();
});

add_action('admin_enqueue_scripts', function () {
    $id = get_the_ID() ?: (int) ($_GET['post'] ?? 0);
    if (! themosis_owned_editor_post($id)) {
        return;
    }
    wp_localize_script('themosis-audit-core', 'themosisGlobal', [
        'api' => ['base_url' => WP_HOME . '/wp-api/themosis/v1/'],
        'post' => ['ID' => $id],
        'metabox' => ['themosis_regression_editor'],
    ]);
    add_filter('script_loader_src', function ($src, $handle) {
        if ($handle !== 'themosis-audit-core') {
            return $src;
        }

        return add_query_arg('sha256', hash_file('sha256', '/framework/dist/js/themosis.core.js'),
            WP_HOME . '/audit-editor-core');
    }, 30, 2);
}, 30);

add_action('init', function () {
    if (! themosis_owned_editor_state()) {
        return;
    }
    app('router')->get('audit-editor-core', function () {
        return new \Symfony\Component\HttpFoundation\BinaryFileResponse('/framework/dist/js/themosis.core.js',
            200, ['Content-Type' => 'application/javascript', 'Cache-Control' => 'no-store']);
    });
});
