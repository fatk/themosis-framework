<?php

namespace Themosis\Tests\User;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;
use Themosis\Field\Factory;
use Themosis\Forms\Fields\FieldsRepository;
use Themosis\Hook\ActionBuilder;
use Themosis\Support\Section;
use Themosis\Tests\Application;
use Themosis\Tests\ViewFactory;
use Themosis\User\UserField;

class UserFieldTest extends TestCase
{
    use Application;
    use ViewFactory;

    private $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = \Illuminate\Container\Container::getInstance();
    }

    protected function tearDown(): void
    {
        \Illuminate\Container\Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    protected function getFieldFactory()
    {
        $app = $this->getApplication();

        return new Factory($app, $this->getViewFactory($app));
    }

    protected function getUserField()
    {
        $app = $this->getApplication();

        return new UserField(
            new FieldsRepository(),
            new ActionBuilder($app),
            $this->getViewFactory($app),
            new \Illuminate\Validation\Factory(new Translator(new FileLoader(new Filesystem(), ''), 'en_US')),
        );
    }

    public function testUserWithWrongOptions()
    {
        $user = $this->getUserField();

        $this->expectException(\InvalidArgumentException::class);

        $user->make(['unknown']);
    }

    public function testUserAddFieldsIndividually()
    {
        $fields = $this->getFieldFactory();
        $user = $this->getUserField();

        $user->make()
            ->add($text = $fields->text('something'))
            ->add($message = $fields->textarea('message'));

        $this->assertEquals($text, $user->repository()->getFieldByName('something'));
        $this->assertEquals($message, $user->repository()->getFieldByName('message'));
    }

    public function testUserAddFieldsWithSections()
    {
        $fields = $this->getFieldFactory();
        $user = $this->getUserField();

        $user->make()
            ->add(new Section('general', 'General', [
                $text = $fields->text('test'),
                $email = $fields->email('email'),
            ]));

        $this->assertEquals($text, $user->repository()->getFieldByName('test'));
        $this->assertEquals($email, $user->repository()->getFieldByName('email'));
    }

    public function testSubmissionCollectionScopesOmittedControlsAndRetainsExplicitValues()
    {
        $factory = $this->getFieldFactory();
        $manager = $this->getUserField()->make()
            ->add($factory->text('note', ['rules' => 'required']))
            ->add($factory->checkbox('flag'))
            ->add($factory->choice('choices', ['multiple' => true, 'choices' => ['A' => 'a']]))
            ->add($factory->checkbox('untouched'));
        $collect = new \ReflectionMethod($manager, 'getUserData');
        $this->assertSame([], $collect->invoke($manager, \Illuminate\Http\Request::create('/', 'POST', [])));
        $request = \Illuminate\Http\Request::create('/', 'POST', [
            '_themosis_fields' => ['th_note' => 1, 'th_flag' => 1, 'th_choices' => 1, 'th_unknown' => 1],
        ]);
        $this->assertSame(['th_flag' => false, 'th_choices' => []], $collect->invoke($manager, $request));
        $request = \Illuminate\Http\Request::create('/', 'POST', ['th_note' => null, 'th_flag' => false, 'th_choices' => []]);
        $this->assertSame(['th_note' => null, 'th_flag' => false, 'th_choices' => []], $collect->invoke($manager, $request));
        $request = \Illuminate\Http\Request::create('/', 'POST', ['_themosis_fields' => 'invalid']);
        $this->assertSame([], $collect->invoke($manager, $request));
    }
}
