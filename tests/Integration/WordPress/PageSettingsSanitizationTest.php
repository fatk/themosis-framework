<?php

namespace Themosis\Tests\Integration\WordPress;

use Themosis\Support\Facades\Field;

class PageSettingsSanitizationTest extends WordPressTestCase
{
    public function testRegisteredCallbacksValidateSuppliedValuesIndependently(): void
    {
        $globals = [];
        foreach (['wp_registered_settings', 'wp_settings_fields', 'wp_settings_sections', 'new_allowed_options', 'wp_settings_errors', 'wp_rest_server'] as $name) {
            $globals[$name] = [array_key_exists($name, $GLOBALS), $GLOBALS[$name] ?? null];
        }
        if (isset($GLOBALS['wp_rest_server'])) { $GLOBALS['wp_rest_server'] = clone $GLOBALS['wp_rest_server']; }
        require_once ABSPATH . 'wp-admin/includes/template.php';
        $page = app('page')->make($this->ownership, 'Owned settings')->setPrefix($this->ownership . '_');
        $first = Field::text('first', ['rules' => 'required|min:5', 'messages' => ['required' => 'Required owned setting']]);
        $second = Field::integer('second', ['rules' => 'required|integer|min:10', 'data_type' => 'integer']);
        $page->addSettings('general', [$first, $second]);
        $page->showInRest();
        $page->configureSettings();
        $keys = [$first->getName(), $second->getName()];
        $this->ownedOptions = $keys;
        try {
            $_POST = [];
            update_option($keys[0], '');
            self::assertSame('', get_option($keys[0]));
            self::assertSame('Required owned setting', get_settings_errors($page->getSlug())[0]['message']);
            update_option($keys[1], '12');
            self::assertEquals(12, get_option($keys[1]));
            update_option($keys[0], 'valid-first');
            self::assertSame('valid-first', get_option($keys[0]));
            $_POST = [$keys[0] => 'valid-post', $keys[1] => '99'];
            update_option($keys[0], '');
            self::assertSame('valid-first', get_option($keys[0]));
            update_option($keys[1], '2');
            self::assertEquals(12, get_option($keys[1]));
            $_POST = [$keys[0] => '', $keys[1] => '2'];
            update_option($keys[0], 'new-valid');
            update_option($keys[0], 'again-valid');
            self::assertSame('again-valid', get_option($keys[0]));
            $_POST = [$keys[0] => 'form-valid', $keys[1] => '25'];
            foreach ($_POST as $key => $value) { update_option($key, $value); }
            self::assertSame('form-valid', get_option($keys[0]));
            self::assertEquals(25, get_option($keys[1]));
            wp_set_current_user($this->createUser('administrator'));
            $request = new \WP_REST_Request('POST', '/wp/v2/settings');
            $request->set_body_params([$keys[0] => 'rest-valid', $keys[1] => 30]);
            $response = rest_do_request($request);
            self::assertSame(200, $response->get_status());
            self::assertSame('rest-valid', get_option($keys[0]));
            $request->set_body_params([$keys[0] => 'bad', $keys[1] => 2]);
            rest_do_request($request);
            self::assertSame('rest-valid', get_option($keys[0]));
            self::assertEquals(30, get_option($keys[1]));
        } finally {
            foreach ($keys as $key) { unregister_setting($page->getSlug(), $key); }
            app()->offsetUnset('page.' . $page->getSlug());
            foreach ($globals as $name => [$exists, $value]) {
                if ($exists) { $GLOBALS[$name] = $value; } else { unset($GLOBALS[$name]); }
            }
        }
    }
}
