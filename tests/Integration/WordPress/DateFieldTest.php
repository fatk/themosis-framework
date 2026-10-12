<?php

namespace Themosis\Tests\Integration\WordPress;

use PHPUnit\Framework\Attributes\DataProvider;
use Themosis\Support\Facades\Field;

class DateFieldTest extends WordPressTestCase
{
    public function testMetaboxSubmissionValidatesDateAndPreservesInvalidStoredValue(): void
    {
        wp_set_current_user($this->createUser('administrator'));
        $post = $this->createPost();
        $date = Field::date('owned_date', ['rules' => 'nullable|date_format:Y-m-d']);
        $text = Field::text('owned_date_control');
        $box = app('metabox')->make($this->ownership, 'post')->add($date)->add($text);
        try {
            foreach (['2026-10-12', '2026-02-30', ''] as $value) {
                $request = \Illuminate\Http\Request::create('/', 'PUT', ['fields' => [
                    ['name' => $date->getName(), 'value' => $value],
                    ['name' => $text->getName(), 'value' => 'October 12'],
                ]]);
                $request->query->set('post_id', $post);
                app(\Themosis\Metabox\Manager::class)->saveFields($box, $request);
                $expected = $value === '2026-02-30' ? '2026-10-12' : $value;
                self::assertSame($expected, get_post_meta($post, $date->getName(), true));
                self::assertSame($expected, (new \Themosis\Forms\Resources\Transformers\FieldTransformer())->transform($date)['value']);
                self::assertSame($value === '2026-02-30', ! empty($date->error()));
                self::assertSame('October 12', get_post_meta($post, $text->getName(), true));
                $loaded = Field::date('owned_date');
                $loaded->metaboxGet($post);
                self::assertSame($expected, $loaded->getRawValue());
            }
        } finally {
            app()->offsetUnset('themosis.metabox.' . $box->getId());
        }
    }

    public static function metadataTypes(): array
    {
        return [['post'], ['user'], ['term']];
    }

    #[DataProvider('metadataTypes')]
    public function testPublicDateFactoryPersistsUpdatesReloadsAndClearsOwnedMetadata(string $type): void
    {
        if ($type === 'post') {
            $id = $this->createPost();
        } elseif ($type === 'user') {
            $id = $this->createUser('subscriber');
        } else {
            $term = wp_insert_term($this->ownership, 'category');
            self::assertNotWPError($term);
            $id = $term['term_id'];
            $this->ownedTerms[] = [$id, 'category'];
        }
        $save = $type === 'post' ? 'metaboxSave' : $type . 'Save';
        $load = $type === 'post' ? 'metaboxGet' : $type . 'Get';
        $get = 'get_' . $type . '_meta';
        $date = Field::date('owned_date', ['rules' => 'nullable|date_format:Y-m-d']);
        foreach (['2026-10-12', '2027-01-02', ''] as $value) {
            $date->$save($value, $id);
            self::assertSame($value, $get($id, $date->getName(), true));
            self::assertSame($value !== '', metadata_exists($type, $id, $date->getName()));
            $loaded = Field::date('owned_date');
            $loaded->$load($id);
            self::assertSame($value, $loaded->getRawValue());
        }
        $text = Field::text('owned_date_control');
        $text->$save('October 12', $id);
        self::assertSame('October 12', $get($id, $text->getName(), true));
        self::assertSame('text', $text->getType());
    }
}
