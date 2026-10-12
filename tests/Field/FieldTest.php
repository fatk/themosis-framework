<?php

namespace Themosis\Tests\Field;

use PHPUnit\Framework\TestCase;
use Themosis\Field\Factory;
use Themosis\Tests\Application;
use Themosis\Tests\ViewFactory;

class FieldTest extends TestCase
{
    use Application;
    use ViewFactory;

    protected function getFieldFactory()
    {
        return new Factory(
            $this->getApplication(),
            $this->getViewFactory($this->getApplication(), [__DIR__ . '/views']),
        );
    }

    public function testLegacyDateFactoryMapsFeaturesAndAttributes(): void
    {
        $date = $this->getFieldFactory()->date('birthday', [
            'title' => 'Birth date', 'info' => 'ISO date', 'rules' => 'nullable|date_format:Y-m-d',
        ], ['class' => 'custom-date', 'min' => '2020-01-01', 'data-field' => 'text']);
        self::assertInstanceOf(\Themosis\Forms\Contracts\FieldTypeInterface::class, $date);
        self::assertSame('date', $date->getType());
        self::assertSame('Birth date', $date->getOption('label'));
        self::assertSame('ISO date', $date->getOption('info'));
        self::assertSame('nullable|date_format:Y-m-d', $date->getOption('rules'));
        self::assertSame('custom-date', $date->getAttributes()['class']);
        self::assertSame('2020-01-01', $date->getAttributes()['min']);
        self::assertSame('date', $date->getAttributes()['data-field']);
        self::assertSame('th_birthday', $date->getName());
        self::assertSame('newtag', $this->getFieldFactory()->date('empty')->getAttributes()['class']);
        self::assertSame('Current label', $this->getFieldFactory()->date('label', ['title' => 'Legacy', 'label' => 'Current label'])->getOption('label'));
    }

    public function testDateRendersInAllExistingNativeThemes(): void
    {
        $this->viewFactory = null;
        $views = $this->getViewFactory($this->getApplication(), array_map(
            fn ($module) => __DIR__ . '/../../src/' . $module . '/views',
            ['Forms', 'User', 'Taxonomy', 'Page'],
        ));
        $factory = new Factory($this->getApplication(), $views);
        foreach (['themosis', 'bootstrap', 'themosis.users', 'themosis.taxonomy', 'themosis.pages'] as $theme) {
            foreach (['2026-10-12', ''] as $value) {
                $date = $factory->date('birthday', ['title' => 'Birth date'], ['min' => '2020-01-01'])
                    ->setTheme($theme)->setValue($value);
                $html = $date->render();
                self::assertStringContainsString('type="date"', $html);
                self::assertStringContainsString('name="th_birthday"', $html);
                self::assertStringContainsString('value="' . $value . '"', $html);
                self::assertStringContainsString('min="2020-01-01"', $html);
                self::assertStringContainsString('type="text"', $factory->text('control')->setTheme($theme)->render());
            }
        }
    }

    public function testDateFormSubmissionUsesConfiguredValidation(): void
    {
        $this->viewFactory = null;
        $views = $this->getViewFactory($this->getApplication(), [__DIR__ . '/../../src/Forms/views']);
        $fields = new Factory($this->getApplication(), $views);
        $translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\FileLoader(new \Illuminate\Filesystem\Filesystem(), ''), 'en_US');
        $forms = new \Themosis\Forms\FormFactory(
            new \Illuminate\Validation\Factory($translator, $this->getApplication()),
            $views, new \League\Fractal\Manager(), new \Themosis\Forms\Resources\Factory(),
        );
        foreach (['2026-10-12' => true, '' => true, '2026-02-30' => false, 'October 12' => false] as $value => $valid) {
            $form = $forms->make(null, ['flush' => false, 'csrf' => false])
                ->add($date = $fields->date('birthday', ['rules' => 'nullable|date_format:Y-m-d']))
                ->add($fields->text('control'))
                ->get();
            $form->handleRequest(\Illuminate\Http\Request::create('/', 'POST', ['th_birthday' => $value, 'th_control' => 'October 12']));
            self::assertSame($valid, $form->isValid());
            self::assertSame($valid ? $value : '', $form->repository()->getField('birthday')->getRawValue());
            self::assertSame('October 12', $form->repository()->getField('control')->getRawValue());
            self::assertStringContainsString('type="date"', $form->render());
            self::assertStringContainsString('type="text"', $form->render());
        }
    }

    public function test_field_view_can_have_custom_data()
    {
        $factory = $this->getFieldFactory();

        $text = $factory->text('someone')
            ->setView('textfield')
            ->with(['title' => 'myvarvalue']);

        $this->assertTrue(false !== strpos($text->render(), 'myvarvalue'));

        $email = $factory->email('email')
            ->with(['email' => 'contactme@company.org'])
            ->setView('emailfield');

        $this->assertTrue(false !== strpos($email->render(), 'contactme@company.org'));
    }
}
