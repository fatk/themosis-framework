<?php

namespace Themosis\Tests\Integration\WordPress;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Themosis\Forms\Fields\Types\TextType;
use Themosis\Forms\Resources\Transformers\FieldTransformer;
use Themosis\Metabox\Manager;

class MetaboxPersistenceTest extends WordPressTestCase
{
    public static function submissions(): array
    {
        return [
            'invalid required replacement' => [['th_note' => ''], 'required', 'original', true, true],
            'omitted required' => [[], 'required', 'original', true, false],
            'valid replacement' => [['th_note' => 'replacement'], 'required', 'replacement', true, false],
            'permitted empty clear' => [['th_note' => ''], '', '', false, false],
            'permitted null clear' => [['th_note' => null], 'nullable', '', false, false],
            'rejected null clear' => [['th_note' => null], 'required', 'original', true, true],
            'unknown submitted field' => [['th_unknown' => 'new'], 'required', 'original', true, false],
        ];
    }

    #[DataProvider('submissions')]
    public function testSubmittedValues(array $values, string $rules, string $expected, bool $exists, bool $error): void
    {
        $this->exercise($values, $rules, $expected, $exists, $error);
    }

    public function testValidSiblingSavesWhileInvalidRequiredFieldIsPreserved(): void
    {
        $this->exercise(['th_note' => '', 'th_sibling' => 'updated'], 'required', 'original', true, true);
    }

    public function testOmittedRequiredFieldHasNoErrorWhileSubmittedSiblingSaves(): void
    {
        $this->exercise(['th_sibling' => 'updated'], 'required', 'original', true, false);
    }

    public function testZeroReachesSaverUnchanged(): void
    {
        wp_set_current_user($this->createUser('administrator'));
        $post = $this->createPost();
        $box = app('metabox')->make($this->ownership, 'post');
        $field = new class('zero') extends TextType {
            public array $saved = [];
            public function metaboxSave($value, int $post_id) { $this->saved[] = $value; }
        };
        $field->setOptions(['rules' => 'numeric']);
        $box->add($field);
        try {
            app(Manager::class)->saveFields($box, $this->request($post, ['th_zero' => 0]));
            self::assertSame([0], $field->saved);
        } finally {
            app()->offsetUnset('themosis.metabox.' . $box->getId());
        }
    }

    public function testExplicitNullReachesSaverWhileOmittedFieldDoesNot(): void
    {
        wp_set_current_user($this->createUser('administrator'));
        $post = $this->createPost();
        $box = app('metabox')->make($this->ownership, 'post');
        $field = new class('nullable') extends TextType {
            public array $saved = [];
            public function metaboxSave($value, int $post_id) { $this->saved[] = $value; }
        };
        $field->setOptions(['rules' => 'nullable']);
        $box->add($field);
        try {
            $manager = app(Manager::class);
            $manager->saveFields($box, $this->request($post, ['th_nullable' => null]));
            self::assertSame([null], $field->saved);
            $manager->saveFields($box, $this->request($post, []));
            self::assertSame([null], $field->saved);
        } finally {
            app()->offsetUnset('themosis.metabox.' . $box->getId());
        }
    }

    private function exercise(array $values, string $rules, string $expected, bool $exists, bool $error): void
    {
        wp_set_current_user($this->createUser('administrator'));
        $post = $this->createPost();
        $box = app('metabox')->make($this->ownership, 'post');
        $note = new TextType('note');
        $note->setOptions(['rules' => $rules, 'messages' => ['required' => 'A note is required.']]);
        $sibling = new TextType('sibling');
        $sibling->setOptions([]);
        $box->add($note)->add($sibling);
        update_post_meta($post, 'th_note', 'original');
        update_post_meta($post, 'th_sibling', 'sibling-original');
        try {
            self::assertSame($box, app(Manager::class)->saveFields($box, $this->request($post, $values)));
            self::assertSame($expected, get_post_meta($post, 'th_note', true));
            self::assertSame($exists, metadata_exists('post', $post, 'th_note'));
            self::assertSame($expected, $note->getRawValue());
            self::assertSame($error ? ['A note is required.'] : [], $note->error());
            $response = (new FieldTransformer())->transform($note);
            self::assertSame($expected, $response['value']);
            self::assertSame($error ? ['A note is required.'] : [], $response['validation']['messages']);
            $siblingValue = $values['th_sibling'] ?? 'sibling-original';
            self::assertSame($siblingValue, get_post_meta($post, 'th_sibling', true));
            self::assertTrue(metadata_exists('post', $post, 'th_sibling'));
            self::assertSame($siblingValue, $sibling->getRawValue());
            self::assertSame([], $sibling->error());
            self::assertFalse(metadata_exists('post', $post, 'th_unknown'));
        } finally {
            app()->offsetUnset('themosis.metabox.' . $box->getId());
        }
    }

    private function request(int $post, array $values): Request
    {
        $fields = [];
        foreach ($values as $name => $value) {
            $fields[] = ['name' => $name, 'value' => $value];
        }
        $request = Request::create('/', 'PUT', ['fields' => $fields]);
        $request->query->set('post_id', $post);

        return $request;
    }
}
