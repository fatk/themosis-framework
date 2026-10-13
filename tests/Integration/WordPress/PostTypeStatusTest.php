<?php

namespace Themosis\Tests\Integration\WordPress;

use PHPUnit\Framework\Attributes\DataProvider;

class PostTypeStatusTest extends WordPressTestCase
{
    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slug = 'th_st_' . bin2hex(random_bytes(4));
    }

    public static function submissions(): array
    {
        return [
            // role, WordPress computed value, $_POST post_status, $_REQUEST post_status, $_POST _status, expected
            'contributor forged publish' => ['contributor', 'pending', 'pending', 'publish', null, 'pending'],
            'contributor custom status' => ['contributor', 'pending', 'pending', 'archived', null, 'pending'],
            'contributor quick edit private' => ['contributor', 'pending', 'pending', 'pending', 'private', 'pending'],
            'contributor unknown status' => ['contributor', 'draft', 'draft', 'anything', null, 'draft'],
            'contributor draft' => ['contributor', 'draft', 'draft', 'draft', null, 'draft'],
            'editor custom status' => ['editor', 'publish', 'publish', 'archived', null, 'archived'],
            'editor quick edit custom status' => ['editor', 'publish', 'publish', 'publish', 'archived', 'archived'],
            'editor new post publish' => ['editor', 'publish', 'publish', 'draft', null, 'archived'],
            'editor unregistered status' => ['editor', 'publish', 'publish', 'private', null, 'publish'],
            'editor draft' => ['editor', 'draft', 'draft', 'draft', null, 'draft'],
        ];
    }

    #[DataProvider('submissions')]
    public function testRequestedStatusRequiresRegistrationAndPublishCapability(string $role, string $value, string $post, string $request, ?string $quick, string $expected): void
    {
        register_post_type($this->slug, ['capability_type' => 'post']);

        try {
            wp_set_current_user($this->createUser($role));
            $type = app('posttype')->make($this->slug, 'Tests', 'Test')->status(['archived', 'reviewed']);
            $_POST = array_filter(['post_type' => $this->slug, 'post_status' => $post, '_status' => $quick]);
            $_REQUEST = ['post_type' => $this->slug, 'post_status' => $request];

            self::assertSame($expected, $type->applyStatus($value));
        } finally {
            unregister_post_type($this->slug);
        }
    }

    public function testOtherPostTypesKeepTheComputedValue(): void
    {
        register_post_type($this->slug, ['capability_type' => 'post']);

        try {
            wp_set_current_user($this->createUser('editor'));
            $type = app('posttype')->make($this->slug, 'Tests', 'Test')->status(['archived']);
            $_POST = ['post_type' => 'post', 'post_status' => 'publish'];
            $_REQUEST = ['post_type' => 'post', 'post_status' => 'archived'];

            self::assertSame('publish', $type->applyStatus('publish'));
        } finally {
            unregister_post_type($this->slug);
        }
    }
}
