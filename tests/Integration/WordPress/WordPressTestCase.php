<?php

namespace Themosis\Tests\Integration\WordPress;

use PHPUnit\Framework\TestCase;

abstract class WordPressTestCase extends TestCase
{
    protected array $ownedPosts = [];
    protected array $ownedUsers = [];
    protected array $ownedTerms = [];
    protected array $ownedOptions = [];
    protected string $ownership;
    private array $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownership = 'themosis_test_' . bin2hex(random_bytes(8));
        $hooks = [];
        foreach ($GLOBALS['wp_filter'] as $key => $hook) {
            $hooks[$key] = clone $hook;
        }
        $this->state = [
            'user' => get_current_user_id(),
            'hooks' => $hooks,
            'actions' => $GLOBALS['wp_actions'],
            'filters' => $GLOBALS['wp_filters'],
            'post' => $GLOBALS['post'] ?? null,
            'screen' => $GLOBALS['current_screen'] ?? null,
            'get' => $_GET, 'post_request' => $_POST, 'request' => $_REQUEST, 'server' => $_SERVER,
            'container' => \Illuminate\Container\Container::getInstance(),
        ];
    }

    protected function createUser(string $role): int
    {
        $id = wp_insert_user([
            'user_login' => $this->ownership . '_' . count($this->ownedUsers),
            'user_pass' => wp_generate_password(), 'role' => $role,
        ]);
        self::assertNotWPError($id);
        $this->ownedUsers[] = $id;

        return $id;
    }

    protected function createPost(array $attributes = []): int
    {
        $id = wp_insert_post(array_merge([
            'post_title' => $this->ownership, 'post_status' => 'draft', 'post_type' => 'post',
        ], $attributes), true);
        self::assertNotWPError($id);
        $this->ownedPosts[] = $id;

        return $id;
    }

    protected static function assertNotWPError($value): void
    {
        self::assertFalse(is_wp_error($value), is_wp_error($value) ? $value->get_error_message() : '');
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->ownedPosts as $id) {
                wp_delete_post($id, true);
            }
            foreach ($this->ownedTerms as [$id, $taxonomy]) {
                wp_delete_term($id, $taxonomy);
            }
            foreach ($this->ownedUsers as $id) {
                wp_delete_user($id);
            }
            foreach ($this->ownedOptions as $key) {
                delete_option($key);
            }
        } finally {
            $GLOBALS['wp_filter'] = $this->state['hooks'];
            $GLOBALS['wp_actions'] = $this->state['actions'];
            $GLOBALS['wp_filters'] = $this->state['filters'];
            $GLOBALS['post'] = $this->state['post'];
            $GLOBALS['current_screen'] = $this->state['screen'];
            $_GET = $this->state['get']; $_POST = $this->state['post_request'];
            $_REQUEST = $this->state['request']; $_SERVER = $this->state['server'];
            wp_set_current_user($this->state['user']);
            \Illuminate\Container\Container::setInstance($this->state['container']);
            parent::tearDown();
        }
    }
}
