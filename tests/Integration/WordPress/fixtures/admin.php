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
            $id = wp_insert_user(['user_login' => $login, 'user_pass' => $password, 'user_email' => $login . '@example.invalid', 'role' => $role]);
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
    if ($command === 'setup-fields') {
        $owner = $state['owner'];
        foreach ($state['users'] as $user) {
            update_user_meta($user['id'], 'th_profile_note', 'original-profile-note');
            update_user_meta($user['id'], 'th_profile_flag', '1');
            delete_user_meta($user['id'], 'th_profile_choices');
            add_user_meta($user['id'], 'th_profile_choices', 'a');
        }
        if (empty($state['terms'])) {
            $term = wp_insert_term($owner . '_category', 'category');
            if (is_wp_error($term)) { throw new RuntimeException($term->get_error_message()); }
            $state['terms'] = [$term['term_id']];
            update_term_meta($term['term_id'], '_themosis_http_owner', $owner);
            update_option($key, $state, false);
        }
        foreach ($state['terms'] as $id) {
            update_term_meta($id, 'th_term_note', 'original-term-note');
            update_term_meta($id, 'th_term_flag', '1');
            delete_term_meta($id, 'th_term_choices');
            add_term_meta($id, 'th_term_choices', 'a');
        }

        return ['owner' => $owner, 'terms' => $state['terms'], 'users' => array_map(fn ($user) => $user['id'], $state['users'])];
    }
    if ($command === 'inspect') {
        $result = ['owner' => $state['owner'], 'posts' => []];
        $result['settings'] = ['note' => get_option($state['owner'] . '_settings_note'), 'count' => get_option($state['owner'] . '_settings_count')];
        $result['page_action_count'] = (int) get_option($state['owner'] . '_page_action_count', 0);
        foreach ($state['posts'] as $role => $id) {
            $result['posts'][$role] = ['id' => $id, 'meta' => get_post_meta($id)];
        }

        foreach ($state['users'] as $role => $user) {
            $result['users'][$role] = ['id' => $user['id'], 'meta' => array_intersect_key(get_user_meta($user['id']), array_flip(['th_profile_note', 'th_profile_flag', 'th_profile_choices', 'th_profile_zero_text', 'th_profile_zero_number', 'th_profile_zero_integer', 'th_profile_zero_choice']))];
        }
        foreach ($state['terms'] ?? [] as $id) {
            $result['terms'][$id] = ['meta' => get_term_meta($id)];
        }

        return $result;
    }
    if ($command !== 'cleanup') { throw new RuntimeException('Unknown HTTP fixture command.'); }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($state['terms'] ?? [] as $id) {
        if (get_term_meta($id, '_themosis_http_owner', true) === $state['owner']) { wp_delete_term($id, 'category'); }
    }
    foreach ($state['posts'] as $id) {
        if (get_post_meta($id, '_themosis_http_owner', true) === $state['owner']) { wp_delete_post($id, true); }
    }
    foreach ($state['users'] as $user) {
        $record = get_userdata($user['id']);
        if ($record && $record->user_login === $user['login'] && str_starts_with($record->user_login, $state['owner'] . '_')) { wp_delete_user($user['id']); }
    }
    delete_option($key);
    delete_option($state['owner'] . '_page_action_count');
    delete_option($state['owner'] . '_settings_note');
    delete_option($state['owner'] . '_settings_count');

    return ['cleaned' => $state['owner']];
}

add_action('init', function () {
    if (! ($state = get_option('themosis_owned_http_fixture'))) { return; }
    app('router')->get('audit-route-url', function () {
        return new \Illuminate\Http\JsonResponse(['generated_url' => route('audit.generated.destination', ['id' => 12, 'q' => 'yes'])]);
    })->name('audit.generated.source');
    app('router')->get('audit-route-destination/{id}', function ($id) {
        return new \Illuminate\Http\JsonResponse(['destination' => 'owned-named-route', 'id' => $id, 'q' => app('request')->query('q')]);
    })->name('audit.generated.destination');
    app('router')->getRoutes()->refreshNameLookups();
    $userFields = app('themosis.user.field')->make();
    $taxonomy = new \Themosis\Taxonomy\Taxonomy('category', ['post'], app(), app('action'));
    $termFields = app('taxonomy.field')->make($taxonomy);
    foreach ([[$userFields, 'profile'], [$termFields, 'term']] as [$fields, $prefix]) {
        $fields->add(\Themosis\Support\Facades\Field::text($prefix . '_note', ['label' => 'Regression note', 'rules' => 'required']))
            ->add(\Themosis\Support\Facades\Field::checkbox($prefix . '_flag', ['label' => 'Regression flag']))
            ->add(\Themosis\Support\Facades\Field::choice($prefix . '_choices', ['label' => 'Regression choices', 'multiple' => true, 'expanded' => true, 'choices' => ['Choice A' => 'a', 'Choice B' => 'b']]))
            ->add(\Themosis\Support\Facades\Field::text($prefix . '_zero_text', ['label' => 'Zero text']))
            ->add(\Themosis\Support\Facades\Field::number($prefix . '_zero_number', ['label' => 'Zero number']))
            ->add(\Themosis\Support\Facades\Field::integer($prefix . '_zero_integer', ['label' => 'Zero integer']))
            ->add(\Themosis\Support\Facades\Field::choice($prefix . '_zero_choice', ['label' => 'Zero choice', 'choices' => ['Zero' => 0, 'Seven' => 7]]))
            ->set();
    }
    \Themosis\Support\Facades\Page::make('themosis_regression_settings', 'Themosis regression settings')
        ->setPrefix($state['owner'] . '_settings_')
        ->addSections([new \Themosis\Support\Section('general', 'Validated settings')])
        ->addSettings('general', [
            \Themosis\Support\Facades\Field::text('note', ['label' => 'Validated note', 'rules' => 'required|min:5']),
            \Themosis\Support\Facades\Field::integer('count', ['label' => 'Validated count', 'rules' => 'required|integer|min:10']),
        ])->set();
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
    \Themosis\Support\Facades\Metabox::make('themosis_regression_zero')
        ->setTitle('Themosis regression zero')
        ->add(\Themosis\Support\Facades\Field::text('zero_text'))
        ->add(\Themosis\Support\Facades\Field::number('zero_number'))
        ->add(\Themosis\Support\Facades\Field::integer('zero_integer'))
        ->add(\Themosis\Support\Facades\Field::choice('zero_choice', ['choices' => ['Zero' => 0, 'Seven' => 7]]))
        ->set();
}, 5);

if (PHP_SAPI === 'cli' && ($command = getenv('THEMOSIS_HTTP_FIXTURE'))) {
    echo json_encode(themosis_http_fixture_command($command), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
}

// Owned HTTPS acceptance endpoint; response policy is chosen explicitly by the route.
add_action('template_redirect', function () {
    if (! get_option('themosis_owned_http_fixture') || ! isset($_GET['themosis_response_policy'])) { return; }
    $policies = [
        'private' => 'private, max-age=60',
        'no-store' => 'private, no-store, no-cache',
        'no-cache' => 'no-cache',
        'public' => 'public, max-age=300',
        'session' => 'private, no-store',
        'laravel-auth' => 'private, no-store',
    ];
    $case = sanitize_key($_GET['themosis_response_policy']);
    if (! isset($policies[$case])) { return; }
    $request = \Illuminate\Http\Request::capture();
    $request->setRouteResolver(fn () => new \Themosis\Route\Route('GET', 'privacy', fn () => null));
    if ($case === 'laravel-auth') { $request->setUserResolver(fn () => (object) ['id' => 'owned-fixture']); }
    if ($case === 'session') {
        $session = new \Illuminate\Session\Store('owned_privacy_fixture', new \Illuminate\Session\ArraySessionHandler(120));
        $session->put('cart', ['owned-example']);
        $request->setLaravelSession($session);
    }
    $response = new \Illuminate\Http\Response('Owned response policy: ' . $case, 200, ['Cache-Control' => $policies[$case], 'Content-Type' => 'text/plain']);
    (new \Themosis\Route\Middleware\WordPressHeaders())->handle($request, fn () => $response)->send();
    exit;
}, 0);
