<?php

$bootstrap = getenv('THEMOSIS_WP_BOOTSTRAP');
if (! $bootstrap || ! is_file($bootstrap)) {
    throw new RuntimeException('Set THEMOSIS_WP_BOOTSTRAP to an explicit isolated WordPress bootstrap.');
}
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require $bootstrap;

if (DB_HOST !== 'mysql' || DB_NAME !== 'themosis_audit' || $GLOBALS['wpdb']->prefix !== 'audit_'
    || get_option('siteurl') !== 'https://themosis-audit.local.dev/cms') {
    throw new RuntimeException('WordPress integration tests require the isolated audit database and fixture host.');
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once __DIR__ . '/WordPressTestCase.php';
