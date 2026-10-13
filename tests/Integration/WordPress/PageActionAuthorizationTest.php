<?php

namespace Themosis\Tests\Integration\WordPress;

use PHPUnit\Framework\Attributes\DataProvider;
use Themosis\Page\Page;

class PageActionAuthorizationTest extends WordPressTestCase
{
    public static function dispatches(): array
    {
        return [
            ['subscriber', 'GET', 'manage_options', false, 403],
            ['subscriber', 'POST', 'manage_options', false, 403],
            ['guest', 'POST', 'manage_options', false, 403],
            ['administrator', 'GET', 'manage_options', false, 405],
            ['administrator', 'PUT', 'manage_options', false, 405],
            ['administrator', 'POST', 'manage_options', false, null],
            ['subscriber', 'POST', 'read', false, null],
            ['administrator', 'POST', 'themosis_missing_capability', false, 403],
            ['subscriber', 'POST', 'manage_network_options', true, 403],
            ['administrator', 'POST', 'manage_network_options', true, 403],
            ['network-manager', 'POST', 'manage_network_options', true, null],
            ['administrator', 'POST', 'manage_options', false, 403, ['HTTP_SEC_FETCH_SITE' => 'cross-site']],
            ['administrator', 'POST', 'manage_options', false, 403, ['HTTP_ORIGIN' => 'https://attacker.invalid']],
            ['administrator', 'POST', 'manage_options', false, null, ['HTTP_SEC_FETCH_SITE' => 'same-origin']],
        ];
    }

    #[DataProvider('dispatches')]
    public function testDispatchAuthorization(string $role, string $method, string $capability, bool $network, ?int $denial, array $headers = []): void
    {
        unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN']);
        $_SERVER = array_merge($_SERVER, $headers);
        $user = $role === 'guest' ? 0 : $this->createUser($role === 'network-manager' ? 'subscriber' : $role);
        if ($role === 'network-manager') { get_userdata($user)->add_cap('manage_network_options'); }
        wp_set_current_user($user);
        $_SERVER['REQUEST_METHOD'] = $method;
        $key = $this->ownership . '_counter';
        $this->ownedOptions[] = $key;
        add_option($key, 0);
        $page = app('page')->make($this->ownership, 'Owned action')->setCapability($capability);
        if ($network) { $page->network(); }
        $page->route('save', function (Page $argument) use ($page, $key) {
            self::assertSame($page, $argument);
            update_option($key, (int) get_option($key) + 1);
        }, 'post');
        $page->parsePostRoute();
        $response = null;
        add_filter('wp_die_handler', function () use (&$response) {
            return function ($message, $title, $args) use (&$response) {
                $response = $args['response'];
                throw new \RuntimeException('Owned wp_die interception');
            };
        });
        try {
            try {
                do_action('admin_post_' . $page->getAction('save'));
            } catch (\RuntimeException $exception) {
                self::assertSame('Owned wp_die interception', $exception->getMessage());
            }
            self::assertSame($denial, $response);
            self::assertSame($denial === null ? 1 : 0, (int) get_option($key));
        } finally {
            app()->offsetUnset('page.' . $page->getSlug());
        }
    }

    public function testCallbackRetainsOwnershipOfNonceValidation(): void
    {
        wp_set_current_user($this->createUser('administrator'));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $key = $this->ownership . '_nonce_counter';
        $this->ownedOptions[] = $key;
        add_option($key, 0);
        $page = app('page')->make($this->ownership, 'Owned nonce action');
        $page->route('save', function (Page $argument) use ($key) {
            if (wp_verify_nonce($_POST['_wpnonce'] ?? '', $argument->getAction('save'))) {
                update_option($key, (int) get_option($key) + 1);
            }
        }, 'post');
        $page->parsePostRoute();
        try {
            $_POST = [];
            do_action('admin_post_' . $page->getAction('save'));
            self::assertSame(0, (int) get_option($key));
            $_POST['_wpnonce'] = wp_create_nonce($page->getAction('save'));
            do_action('admin_post_' . $page->getAction('save'));
            self::assertSame(1, (int) get_option($key));
        } finally {
            app()->offsetUnset('page.' . $page->getSlug());
        }
    }
}
