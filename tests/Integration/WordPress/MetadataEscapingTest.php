<?php

namespace Themosis\Tests\Integration\WordPress;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Themosis\Forms\Fields\Types\ColorType;
use Themosis\Forms\Fields\Types\EditorType;
use Themosis\Forms\Fields\Types\EmailType;
use Themosis\Forms\Fields\Types\PasswordType;
use Themosis\Forms\Fields\Types\TextareaType;
use Themosis\Forms\Fields\Types\TextType;
use Themosis\Metabox\Manager;
use Themosis\Taxonomy\Taxonomy;
use Themosis\Taxonomy\TaxonomyField;
use Themosis\Taxonomy\TaxonomyFieldRepository;

class MetadataEscapingTest extends WordPressTestCase
{
    public static function textMetadata(): array
    {
        $cases = [];
        foreach (['post', 'user', 'term'] as $type) {
            foreach ([TextType::class, EmailType::class, PasswordType::class, TextareaType::class, EditorType::class] as $class) {
                $cases[$type . '-' . class_basename($class)] = [$type, $class];
            }
        }
        // Editor explicitly rejects term metadata.
        unset($cases['term-EditorType']);
        // Color inherits post persistence, but explicitly rejects user/term metadata.
        $cases['post-ColorType'] = ['post', ColorType::class];

        return $cases;
    }

    #[DataProvider('textMetadata')]
    public function testRawStringsSurviveCreateUpdateAndReload(string $type, string $class): void
    {
        $id = $this->createRecord($type);
        $field = $this->field($class);
        $save = $type === 'post' ? 'metaboxSave' : $type . 'Save';
        $load = $type === 'post' ? 'metaboxGet' : $type . 'Get';
        foreach ([self::payload('create'), self::payload('update'), 'C:\\raw\\files\\ "quoted" \\"literal', self::payload('final')] as $raw) {
            $field->$save($raw, $id);
            self::assertSame($raw, $field->getRawValue(), 'The internal value stays raw.');
            $this->assertStored($type, $id, $field->getName(), $raw);
            $loaded = $this->field($class);
            $loaded->$load($id);
            self::assertSame($raw, $loaded->getRawValue());
            // Saving the same bytes must neither duplicate a row nor accumulate slashes.
            $field->$save($raw, $id);
            $this->assertStored($type, $id, $field->getName(), $raw);
        }
    }

    public function testDecodedJsonRequestPreservesMetaboxMetadata(): void
    {
        wp_set_current_user($this->createUser('administrator'));
        $id = $this->createPost();
        $box = app('metabox')->make($this->ownership, 'post');
        $field = $this->field(TextType::class);
        $box->add($field);
        try {
            foreach ([self::payload('create'), self::payload('update')] as $raw) {
                $body = json_encode(['fields' => [['name' => $field->getName(), 'value' => $raw]]], JSON_THROW_ON_ERROR);
                $request = Request::createFromBase(\Symfony\Component\HttpFoundation\Request::create('/', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body));
                $request->query->set('post_id', $id);
                self::assertSame($raw, $request->input('fields.0.value'));
                app(Manager::class)->saveFields($box, $request);
                $this->assertStored('post', $id, $field->getName(), $raw);
            }
        } finally {
            app()->offsetUnset('themosis.metabox.' . $box->getId());
        }
    }

    public static function nativeTypes(): array
    {
        return ['user' => ['user'], 'term' => ['term']];
    }

    #[DataProvider('nativeTypes')]
    public function testNativeFormCapturedBeforeMagicQuotesPreservesCreateAndUpdate(string $type): void
    {
        $previousRequest = app('request');
        $registry = $GLOBALS['wp_meta_keys'] ?? [];
        $cookies = $_COOKIE;
        wp_set_current_user($this->createUser('administrator'));
        try {
            app()->instance('request', Request::create('/', 'POST'));
            if ($type === 'user') {
                $manager = app('themosis.user.field')->make();
            } else {
                $taxonomy = new Taxonomy('category', ['post'], app(), app('action'));
                $manager = new TaxonomyField($taxonomy, new TaxonomyFieldRepository(), app('view'), app('validator'), app('action'), []);
            }
            $field = $this->field(TextType::class);
            $manager->add($field);
            $manager->set();
            foreach ([self::payload('create'), self::payload('update')] as $index => $raw) {
                // The real bootstrap captures Request before wp_magic_quotes().
                // Model that order explicitly, and inspect the boundary before saving.
                $_GET = [];
                $_POST = [$field->getName() => $raw];
                $_REQUEST = $_POST;
                $_SERVER['REQUEST_METHOD'] = 'POST';
                app()->instance('request', Request::capture());
                wp_magic_quotes();
                self::assertSame($raw, app('request')->input($field->getName()));
                self::assertNotSame($raw, $_POST[$field->getName()]);
                self::assertSame($raw, wp_unslash($_POST[$field->getName()]));
                if ($index === 0) {
                    $id = $this->createRecord($type);
                } elseif ($type === 'user') {
                    self::assertNotWPError(wp_update_user(['ID' => $id, 'display_name' => 'Escaping update']));
                } else {
                    self::assertNotWPError(wp_update_term($id, 'category', ['description' => 'Escaping update']));
                }
                $this->assertStored($type, $id, $field->getName(), $raw);
            }
        } finally {
            app()->instance('request', $previousRequest);
            $GLOBALS['wp_meta_keys'] = $registry;
            $_COOKIE = $cookies;
        }
    }

    private static function payload(string $stage): string
    {
        return json_encode(['stage' => $stage, 'quote' => 'say "hello"', 'path' => 'C:\\raw\\files', 'literal' => '\\"'], JSON_THROW_ON_ERROR);
    }

    private function field(string $class)
    {
        $field = new $class('escaping');
        $field->setLocale('en_US');
        $field->setOptions([]);

        return $field;
    }

    private function createRecord(string $type): int
    {
        if ($type === 'post') {
            return $this->createPost();
        }
        if ($type === 'user') {
            return $this->createUser('subscriber');
        }
        $term = wp_insert_term($this->ownership, 'category');
        self::assertNotWPError($term);
        $this->ownedTerms[] = [$term['term_id'], 'category'];

        return $term['term_id'];
    }

    private function assertStored(string $type, int $id, string $name, string $raw): void
    {
        self::assertTrue(metadata_exists($type, $id, $name));
        self::assertSame($raw, get_metadata($type, $id, $name, true));
        self::assertSame([$raw], get_metadata($type, $id, $name, false));
        if (str_starts_with($raw, '{')) {
            self::assertSame(json_decode($raw, true, 512, JSON_THROW_ON_ERROR), json_decode(get_metadata($type, $id, $name, true), true, 512, JSON_THROW_ON_ERROR));
        }
    }
}
