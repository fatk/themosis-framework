<?php

/**
 * Opt-in fixture: require from an audit-only mu plugin. No production bootstrap.
 * CLI: require WordPress first, then set THEMOSIS_HTTP_FIXTURE=setup|inspect|cleanup.
 */
if (! defined('ABSPATH') || DB_HOST !== 'mysql' || DB_NAME !== 'themosis_audit'
    || $GLOBALS['wpdb']->prefix !== 'audit_' || get_option('siteurl') !== 'https://themosis-audit.local.dev/cms') {
    throw new RuntimeException('Admin fixture requires the isolated WordPress audit site.');
}

function themosis_http_fixture_command(string $command): array
{
    $key = 'themosis_owned_http_fixture';
    $state = get_option($key);
    if ($command === 'setup') {
        if ($state) {
            throw new RuntimeException('Clean up the existing owned HTTP fixture before setup.');
        }
        $owner = 'themosis_http_' . bin2hex(random_bytes(6));
        $state = ['owner' => $owner, 'users' => [], 'posts' => []];
        foreach (['administrator', 'editor', 'author', 'subscriber'] as $role) {
            $login = $owner . '_' . $role;
            $password = wp_generate_password(24, false);
            $id = wp_insert_user(['user_login' => $login, 'user_pass' => $password, 'role' => $role]);
            if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
            $state['users'][$role] = ['id' => $id, 'login' => $login, 'password' => $password];
            update_option($key, $state, false);
        }
        foreach (['author', 'administrator'] as $role) {
            $id = wp_insert_post(['post_title' => $owner . '_' . $role, 'post_status' => 'draft', 'post_author' => $state['users'][$role]['id']], true);
            if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
            $state['posts'][$role] = $id;
            update_post_meta($id, '_themosis_http_owner', $owner);
            update_post_meta($id, 'th_note', 'original-valid-note');
            update_post_meta($id, 'th_sibling', 'original-sibling');
            update_post_meta($id, 'th_secret', 'restricted-secret-' . $owner);
            update_option($key, $state, false);
        }

        return $state;
    }
    if (! $state) { return []; }
    if ($command === 'inspect') {
        $result = ['owner' => $state['owner'], 'posts' => []];
        $result['page_action_count'] = (int) get_option($state['owner'] . '_page_action_count', 0);
        foreach ($state['posts'] as $role => $id) {
            $result['posts'][$role] = ['id' => $id, 'meta' => get_post_meta($id)];
        }

        return $result;
    }
    if ($command !== 'cleanup') { throw new RuntimeException('Unknown HTTP fixture command.'); }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($state['posts'] as $id) {
        if (get_post_meta($id, '_themosis_http_owner', true) === $state['owner']) { wp_delete_post($id, true); }
    }
    foreach ($state['users'] as $user) {
        $record = get_userdata($user['id']);
        if ($record && $record->user_login === $user['login'] && str_starts_with($record->user_login, $state['owner'] . '_')) { wp_delete_user($user['id']); }
    }
    delete_option($key);
    delete_option($state['owner'] . '_page_action_count');

    return ['cleaned' => $state['owner']];
}

add_action('init', function () {
    if (! ($state = get_option('themosis_owned_http_fixture'))) { return; }
    \Themosis\Support\Facades\Page::make('themosis_regression_page', 'Themosis regression page')
        ->route('save', function ($page) use ($state) {
            $key = $state['owner'] . '_page_action_count';
            update_option($key, (int) get_option($key, 0) + 1, false);
            wp_send_json_success(['count' => (int) get_option($key), 'action' => $page->getAction('save')]);
        }, 'post');
    \Themosis\Support\Facades\Metabox::make('themosis_regression_public')
        ->setTitle('Themosis regression public')
        ->add(\Themosis\Support\Facades\Field::text('note', ['rules' => ['required', 'min:5']]))
        ->add(\Themosis\Support\Facades\Field::text('sibling'))
        ->set();
    \Themosis\Support\Facades\Metabox::make('themosis_regression_private')
        ->setTitle('Themosis regression private')->setCapability('manage_options')
        ->add(\Themosis\Support\Facades\Field::text('secret'))->set();
}, 5);

if (PHP_SAPI === 'cli' && ($command = getenv('THEMOSIS_HTTP_FIXTURE'))) {
    echo json_encode(themosis_http_fixture_command($command), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
}
