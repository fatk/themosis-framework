<?php

namespace Themosis\Tests\Integration\WordPress;

use PHPUnit\Framework\Attributes\DataProvider;
use Themosis\Forms\Fields\Types\ChoiceType;
use Themosis\Forms\Fields\Types\IntegerType;
use Themosis\Forms\Fields\Types\NumberType;
use Themosis\Forms\Fields\Types\TextType;

class MetadataValueTest extends WordPressTestCase
{
    public static function scalarZeros(): array
    {
        $cases = [];
        foreach (['post', 'user', 'term'] as $type) {
            foreach ([TextType::class, NumberType::class, IntegerType::class, ChoiceType::class] as $field) {
                foreach ([0, '0'] as $index => $zero) {
                    $cases[$type . '-' . class_basename($field) . '-' . $index] = [$type, $field, $zero];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('scalarZeros')]
    public function testScalarZeroCreatesUpdatesAndLoadsMetadata(string $type, string $class, $zero): void
    {
        $id = $this->createRecord($type);
        $field = $this->field($class);
        $name = $field->getName();
        self::assertFalse(metadata_exists($type, $id, $name));

        $this->save($type, $field, $zero, $id);
        $this->assertStored($type, $id, $name, '0');
        $this->assertLoaded($type, $class, $id, '0');

        // Existing zero must take the update path, rather than add(unique).
        $this->save($type, $field, 7, $id);
        $this->assertStored($type, $id, $name, '7');
        $this->save($type, $field, $zero, $id);
        $this->assertStored($type, $id, $name, '0');
        $this->assertLoaded($type, $class, $id, '0');
        $this->save($type, $field, 9, $id);
        $this->assertStored($type, $id, $name, '9');
        $this->assertLoaded($type, $class, $id, '9');
    }

    #[DataProvider('scalarZeros')]
    public function testSeededZeroLoadsAndCanBeReplaced(string $type, string $class, $zero): void
    {
        $id = $this->createRecord($type);
        $field = $this->field($class);
        add_metadata($type, $id, $field->getName(), $zero, true);

        $this->assertLoaded($type, $class, $id, '0');
        $this->save($type, $field, 7, $id);
        $this->assertStored($type, $id, $field->getName(), '7');
    }

    public static function scalarClears(): array
    {
        $cases = [];
        foreach (self::scalarZeros() as $name => [$type, $class, $zero]) {
            $cases[$name] = [$type, $class, is_int($zero) ? null : ''];
        }

        return $cases;
    }

    #[DataProvider('scalarClears')]
    public function testIntentionalScalarEmptyControlDeletesMetadata(string $type, string $class, $empty): void
    {
        $id = $this->createRecord($type);
        $field = $this->field($class);
        add_metadata($type, $id, $field->getName(), 7, true);
        $this->save($type, $field, $empty, $id);

        self::assertFalse(metadata_exists($type, $id, $field->getName()));
        self::assertSame('', get_metadata($type, $id, $field->getName(), true));
    }

    public static function multipleClears(): array
    {
        $cases = [];
        foreach (['post', 'user', 'term'] as $type) {
            foreach ([[], null, ''] as $index => $empty) {
                $cases[$type . '-' . $index] = [$type, $empty];
            }
        }

        return $cases;
    }

    #[DataProvider('multipleClears')]
    public function testMultipleChoiceRetainsZeroAndClearsSelections(string $type, $empty): void
    {
        $id = $this->createRecord($type);
        $field = $this->field(ChoiceType::class, ['multiple' => true]);
        $this->save($type, $field, [0, '7'], $id);
        self::assertSame(['0', '7'], get_metadata($type, $id, $field->getName(), false));

        $loaded = $this->field(ChoiceType::class, ['multiple' => true]);
        $this->load($type, $loaded, $id);
        self::assertSame([0, 7], $loaded->getRawValue());
        $this->save($type, $field, ['0'], $id);
        self::assertSame(['0'], get_metadata($type, $id, $field->getName(), false));
        $this->save($type, $field, $empty, $id);
        self::assertFalse(metadata_exists($type, $id, $field->getName()));
        self::assertSame([], get_metadata($type, $id, $field->getName(), false));
    }

    private function field(string $class, array $options = [])
    {
        $field = new $class('zero');
        $field->setLocale('en_US');
        $field->setOptions(array_merge(['choices' => ['None' => 0, 'Some' => 7, 'Other' => 9]], $options));

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

    private function save(string $type, $field, $value, int $id): void
    {
        $method = $type === 'post' ? 'metaboxSave' : $type . 'Save';
        $field->$method($value, $id);
    }

    private function load(string $type, $field, int $id): void
    {
        $method = $type === 'post' ? 'metaboxGet' : $type . 'Get';
        $field->$method($id);
    }

    private function assertStored(string $type, int $id, string $name, string $expected): void
    {
        self::assertTrue(metadata_exists($type, $id, $name));
        self::assertSame($expected, get_metadata($type, $id, $name, true));
        self::assertSame([$expected], get_metadata($type, $id, $name, false));
    }

    private function assertLoaded(string $type, string $class, int $id, string $expected): void
    {
        $loaded = $this->field($class, ['data' => 7]);
        $this->load($type, $loaded, $id);
        self::assertSame($expected, (string) $loaded->getRawValue());
    }
}
