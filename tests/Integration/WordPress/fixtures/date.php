<?php

// Opt-in fixture loaded after admin.php, only on the isolated audit site.
if (! defined('ABSPATH') || DB_HOST !== 'mysql' || DB_NAME !== 'themosis_audit'
    || $GLOBALS['wpdb']->prefix !== 'audit_' || get_option('siteurl') !== 'https://themosis-audit.local.dev/cms') {
    throw new RuntimeException('Date fixture requires the isolated WordPress audit site.');
}

function themosis_owned_date_state(): ?array
{
    $state = get_option('themosis_owned_http_fixture');

    return is_array($state) && preg_match('/^themosis_http_[a-f0-9]{12}$/D', $state['owner'] ?? '') ? $state : null;
}

add_action('init', function () {
    if (! ($state = themosis_owned_date_state())) {
        return;
    }
    $features = ['title' => 'Regression date', 'info' => 'Native ISO date', 'rules' => 'nullable|date_format:Y-m-d'];
    \Themosis\Support\Facades\Metabox::make('themosis_regression_date')
        ->setTitle('Themosis regression date')
        ->add(\Themosis\Support\Facades\Field::date('regression_date', $features, ['min' => '2020-01-01']))
        ->add(\Themosis\Support\Facades\Field::text('regression_date_text', ['label' => 'Date text control']))
        ->set();
    app('themosis.user.field')->make()
        ->add(\Themosis\Support\Facades\Field::date('profile_date', $features))
        ->add(\Themosis\Support\Facades\Field::text('profile_date_text', ['label' => 'Date text control']))
        ->set();
    $taxonomy = new \Themosis\Taxonomy\Taxonomy('category', ['post'], app(), app('action'));
    app('taxonomy.field')->make($taxonomy)
        ->add(\Themosis\Support\Facades\Field::date('term_date', $features))
        ->add(\Themosis\Support\Facades\Field::text('term_date_text', ['label' => 'Date text control']))
        ->set();

    app('router')->match(['GET', 'POST'], 'audit-date-form/{theme}', function ($theme) use ($state, $features) {
        $post = (int) ($state['posts']['administrator'] ?? 0);
        if (! in_array($theme, ['themosis', 'bootstrap'], true) || ! current_user_can('edit_post', $post)
            || get_post_meta($post, '_themosis_http_owner', true) !== $state['owner']) {
            abort(403);
        }
        $date = \Themosis\Support\Facades\Field::date('form_date', $features);
        $text = \Themosis\Support\Facades\Field::text('form_date_text', ['label' => 'Date text control']);
        $date->metaboxGet($post);
        $text->metaboxGet($post);
        $form = \Themosis\Support\Facades\Form::make(null, ['theme' => $theme, 'flush' => false, 'csrf' => false])
            ->add($date)->add($text)->add(\Themosis\Support\Facades\Field::submit('save', ['label' => 'Save dates']))->get();
        $valid = true;
        if (app('request')->isMethod('post')) {
            $form->handleRequest(app('request'));
            $valid = $form->isValid();
            if ($valid) {
                $date->metaboxSave($date->getRawValue(), $post);
                $text->metaboxSave($text->getRawValue(), $post);
            }
        }

        return new \Illuminate\Http\Response('<!doctype html><html><head><title>Owned date form</title></head><body><h1>Native ' . $theme . ' date form</h1>'
            . $form->render() . '</body></html>', $valid ? 200 : 422);
    });
}, 6);

add_action('admin_enqueue_scripts', function () {
    $state = themosis_owned_date_state();
    $id = get_the_ID() ?: (int) ($_GET['post'] ?? 0);
    if (! $state || ! in_array($id, array_map('intval', $state['posts']), true)
        || get_post_meta($id, '_themosis_http_owner', true) !== $state['owner']) {
        return;
    }
    wp_localize_script('themosis-audit-core', 'themosisGlobal', [
        'api' => ['base_url' => WP_HOME . '/wp-api/themosis/v1/'],
        'post' => ['ID' => $id], 'metabox' => ['themosis_regression_date'],
    ]);
}, 40);
