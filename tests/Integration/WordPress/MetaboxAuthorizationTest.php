<?php

namespace Themosis\Tests\Integration\WordPress;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Themosis\Forms\Fields\Types\TextType;
use Themosis\Metabox\Manager;

class MetaboxAuthorizationTest extends WordPressTestCase
{
    public static function targets(): iterable
    {
        foreach (['administrator', 'editor', 'author', 'subscriber', 'guest'] as $role) {
            foreach (['draft', 'private', 'publish'] as $status) {
                foreach ([true, false] as $own) {
                    foreach ([true, false] as $restricted) {
                        foreach (['GET', 'PUT'] as $method) {
                            yield "$role-$status-" . (int) $own . '-' . (int) $restricted . "-$method" => [$role, $status, $own, $restricted, $method];
                        }
                    }
                }
            }
        }
    }

    #[DataProvider('targets')]
    public function testRoleAndObjectPermissions(string $role, string $status, bool $own, bool $restricted, string $method): void
    {
        $user = $role === 'guest' ? 0 : $this->createUser($role);
        $other = $this->createUser('author');
        $post = $this->createPost(['post_author' => $own ? $user : $other, 'post_status' => $status]);
        wp_set_current_user($user);
        $allowed = current_user_can('edit_post', $post) && (! $restricted || current_user_can('manage_options'));
        $this->exercise($post, $method, $allowed, $restricted);
    }

    public static function inapplicableTargets(): array
    {
        return [
            ['GET', 'post', 'page', null], ['PUT', 'post', ['page'], null],
            ['GET', 'page', 'page', 'wrong.php'], ['PUT', 'page', 'page', 'wrong.php'],
            ['GET', 'post', 'post', null, '0'], ['PUT', 'post', 'post', null, '-1'],
            ['GET', 'post', 'post', null, 'abc'], ['PUT', 'post', 'post', null, '1.5'],
            ['GET', 'post', 'post', null, '999999999'], ['PUT', 'post', 'post', null, null],
        ];
    }

    #[DataProvider('inapplicableTargets')]
    public function testInvalidOrInapplicableTargets(string $method, string $type, $screen, ?string $template, $id = 'created'): void
    {
        wp_set_current_user($this->createUser('administrator'));
        $post = $this->createPost(['post_type' => $type]);
        $this->exercise($post, $method, false, false, $screen, $template, $id === 'created' ? $post : $id);
    }

    public function testMatchingTemplateAndScreenObjectAreAllowed(): void
    {
        wp_set_current_user($this->createUser('administrator'));
        $post = $this->createPost(['post_type' => 'page']);
        update_post_meta($post, '_wp_page_template', 'allowed.php');
        $this->exercise($post, 'GET', true, false, \WP_Screen::get('page'), 'allowed.php');
        $this->exercise($post, 'PUT', true, false, ['page', 'post'], 'allowed.php');
    }

    private function exercise(int $post, string $method, bool $allowed, bool $restricted, $screen = 'post', ?string $template = null, $id = 'created'): void
    {
        $box = app('metabox')->make($this->ownership . '_' . bin2hex(random_bytes(3)), $screen);
        $field = new class('secret') extends TextType {
            public int $loads = 0;
            public int $saves = 0;
            public function metaboxGet(int $id) { $this->loads++; parent::metaboxGet($id); }
            public function metaboxSave($value, int $id) { $this->saves++; parent::metaboxSave($value, $id); }
        };
        $field->setOptions([]);
        $box->add($field);
        if ($restricted) { $box->setCapability('manage_options'); }
        if ($template !== null) { $box->setTemplate('allowed.php', 'page'); }
        update_post_meta($post, $field->getName(), 'restricted-original');
        if ($template !== null && $template !== 'allowed.php') { update_post_meta($post, '_wp_page_template', $template); }
        $request = Request::create('/', $method, ['fields' => [['name' => $field->getName(), 'value' => 'replacement']]]);
        if ($id !== null) { $request->query->set('post_id', $id === 'created' ? $post : $id); }
        try {
            try {
                $manager = app(Manager::class);
                $result = $method === 'GET' ? $manager->getFields($box, $request) : $manager->saveFields($box, $request);
                self::assertTrue($allowed, 'Denied target reached field processing.');
                self::assertSame($box, $result);
            } catch (HttpExceptionInterface $exception) {
                self::assertFalse($allowed);
                self::assertSame(404, $exception->getStatusCode());
            }
            self::assertSame($allowed && $method === 'GET' ? 1 : 0, $field->loads);
            self::assertSame($allowed && $method === 'PUT' ? 1 : 0, $field->saves);
            self::assertSame($allowed && $method === 'PUT' ? 'replacement' : 'restricted-original', get_post_meta($post, $field->getName(), true));
            self::assertTrue(metadata_exists('post', $post, $field->getName()));
        } finally {
            app()->offsetUnset('themosis.metabox.' . $box->getId());
        }
    }
}
