<?php

namespace Themosis\Tests\Integration\WordPress;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Themosis\Forms\Fields\Types\TextType;
use Themosis\Forms\Fields\Types\CheckboxType;
use Themosis\Forms\Fields\Types\ChoiceType;

class TaxonomyFieldPersistenceTest extends WordPressTestCase
{
    public static function submissions(): array
    {
        return [
            'unrelated update' => [[], 'original', 'sibling', ['a'], true, false],
            'partial update' => [['th_sibling' => 'updated'], 'original', 'updated', ['a'], true, false],
            'invalid replacement' => [['th_note' => '', 'th_sibling' => 'updated'], 'original', 'updated', ['a'], true, true],
            'valid replacement' => [['th_note' => 'valid'], 'valid', 'sibling', ['a'], true, false],
            'unknown marker' => [['_themosis_fields' => ['th_unknown' => 1]], 'original', 'sibling', ['a'], true, false],
            'ordinary omitted marked field' => [['_themosis_fields' => ['th_note' => 1]], 'original', 'sibling', ['a'], true, false],
            'scoped checkbox clear' => [['_themosis_fields' => ['th_flag' => 1]], 'original', 'sibling', ['a'], false, false],
            'scoped multiple clear' => [['_themosis_fields' => ['th_choices' => 1]], 'original', 'sibling', [], true, false],
            'custom explicit clears' => [['th_flag' => false, 'th_choices' => []], 'original', 'sibling', [], false, false],
            'valid controls' => [['th_flag' => '1', 'th_choices' => ['b']], 'original', 'sibling', ['b'], true, false],
        ];
    }

    #[DataProvider('submissions')]
    public function testRealUpdatePreservesUnsubmittedAndInvalidMetadata(array $values, string $noteValue, string $siblingValue, array $choicesValue, bool $flagExists, bool $error): void
    {
        $result = wp_insert_term($this->ownership, 'category');
        self::assertNotWPError($result);
        $id = $result['term_id'];
        $this->ownedTerms[] = [$id, 'category'];
        $previousRequest = app('request');
        $registry = $GLOBALS['wp_meta_keys'] ?? [];
        try {
            app()->instance('request', Request::create('/', 'POST', []));
            $taxonomy = new \Themosis\Taxonomy\Taxonomy('category', ['post'], app(), app('action'));
            $manager = new \Themosis\Taxonomy\TaxonomyField($taxonomy, new \Themosis\Taxonomy\TaxonomyFieldRepository(), app('view'), app('validator'), app('action'), []);
            $note = new TextType('note');
            $note->setOptions(['rules' => 'required', 'messages' => ['required' => 'Required note.']]);
            $sibling = new TextType('sibling');
            $sibling->setOptions([]);
            $flag = new CheckboxType('flag');
            $flag->setOptions([]);
            $choices = new ChoiceType('choices');
            $choices->setOptions(['multiple' => true, 'choices' => ['A' => 'a', 'B' => 'b']]);
            $manager->add($note)->add($sibling)->add($flag)->add($choices);
            $manager->set();
            update_term_meta($id, 'th_note', 'original');
            update_term_meta($id, 'th_sibling', 'sibling');
            update_term_meta($id, 'th_flag', '1');
            add_term_meta($id, 'th_choices', 'a');
            app()->instance('request', Request::create('/', 'POST', $values));
            self::assertNotWPError(wp_update_term($id, 'category', ['description' => 'Unrelated description']));
            self::assertSame($noteValue, get_term_meta($id, 'th_note', true));
            self::assertSame($siblingValue, get_term_meta($id, 'th_sibling', true));
            self::assertSame($choicesValue, get_term_meta($id, 'th_choices', false));
            self::assertSame($flagExists, metadata_exists('term', $id, 'th_flag'));
            self::assertSame($error ? ['Required note.'] : [], $note->error());
            self::assertFalse(metadata_exists('term', $id, 'th_unknown'));
        } finally {
            app()->instance('request', $previousRequest);
            $GLOBALS['wp_meta_keys'] = $registry;
        }
    }

    public function testRealCreateSavesExplicitControls(): void
    {
        $previousRequest = app('request');
        $registry = $GLOBALS['wp_meta_keys'] ?? [];
        try {
            $taxonomy = new \Themosis\Taxonomy\Taxonomy('category', ['post'], app(), app('action'));
            $manager = new \Themosis\Taxonomy\TaxonomyField($taxonomy, new \Themosis\Taxonomy\TaxonomyFieldRepository(), app('view'), app('validator'), app('action'), []);
            $flag = new CheckboxType('flag');
            $flag->setOptions([]);
            $choices = new ChoiceType('choices');
            $choices->setOptions(['multiple' => true, 'choices' => ['A' => 'a', 'B' => 'b']]);
            $manager->add($flag)->add($choices);
            $manager->set();
            app()->instance('request', Request::create('/', 'POST', ['th_flag' => '1', 'th_choices' => ['a', 'b']]));
            $result = wp_insert_term($this->ownership, 'category');
            self::assertNotWPError($result);
            $id = $result['term_id'];
            $this->ownedTerms[] = [$id, 'category'];
            self::assertSame('on', get_term_meta($id, 'th_flag', true));
            self::assertSame(['a', 'b'], get_term_meta($id, 'th_choices', false));
        } finally {
            app()->instance('request', $previousRequest);
            $GLOBALS['wp_meta_keys'] = $registry;
        }
    }
}
