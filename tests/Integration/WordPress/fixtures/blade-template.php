<?php

// Opt-in, owned HTTP control. Load after fixtures/admin.php from the audit mu plugin.
if (! defined('ABSPATH') || DB_HOST !== 'mysql' || DB_NAME !== 'themosis_audit'
    || $GLOBALS['wpdb']->prefix !== 'audit_' || get_option('siteurl') !== 'https://themosis-audit.local.dev/cms') {
    throw new RuntimeException('Blade fixture requires the isolated WordPress audit site.');
}
function themosis_owned_template_selector(): string
{
    $selector = app('request')->query('selector', 'post');

    return in_array($selector, ['post', 'page', 'missing'], true) ? $selector : 'post';
}

function themosis_owned_template_path(): string
{
    $state = get_option('themosis_owned_http_fixture');
    if (! is_array($state) || ! preg_match('/^themosis_http_[a-f0-9]{12}$/D', $state['owner'] ?? '')) {
        throw new RuntimeException('Blade control requires the existing owned HTTP fixture.');
    }

    return sys_get_temp_dir() . '/' . $state['owner'] . '-blade-template.php';
}

function themosis_owned_template_clear(): array
{
    $path = themosis_owned_template_path();
    if (is_link($path)) {
        throw new RuntimeException('Refusing to clear a symlink.');
    }
    if (is_file($path)) {
        unlink($path);
    }

    return ['cleared' => $path];
}

add_action('init', function () {
    if (! get_option('themosis_owned_http_fixture')) {
        return;
    }
    app('router')->get('audit-blade-template', function () {
        $path = themosis_owned_template_path();
        if (! is_file($path)) {
            $source = '@template("audit-template::content", themosis_owned_template_selector(), $data)';
            file_put_contents($path, app('blade.compiler')->compileString($source), LOCK_EX);
        }
        $__env = app('view');
        $__env->addNamespace('audit-template', __DIR__ . '/blade-template-views');
        $inherited = 'inherited-value';
        $data = ['first' => 'one,two', 'nested' => ['value' => 'three,four'], 'last' => 'five'];
        ob_start();
        try {
            require $path;
            $rendered = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return new \Illuminate\Http\JsonResponse(['selector' => themosis_owned_template_selector(), 'rendered' => $rendered, 'compiled_path' => $path, 'compiled_sha256' => hash_file('sha256', $path)]);
    });
});
