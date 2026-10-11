<?php

namespace Themosis\Tests\Page;

use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\TestCase;
use Themosis\Core\Application;
use Themosis\Page\PageFactory;
use Themosis\Support\Section;

class PageTest extends TestCase
{
    protected $viewFactory;

    protected function getActionMock()
    {
        return $this->getMockBuilder(\Themosis\Hook\ActionBuilder::class)
            ->onlyMethods(['add'])
            ->disableOriginalConstructor()
            ->getMock();
    }

    protected function getActionStub()
    {
        return $this->createStub(\Themosis\Hook\ActionBuilder::class);
    }

    protected function getFilter()
    {
        return $this->createStub(\Themosis\Hook\FilterBuilder::class);
    }

    protected function getApplication()
    {
        $application = $this->createStub(\Themosis\Core\Application::class);

        $application->method('getLocale')->willReturn('en_US');

        return $application;
    }

    protected function getViewFactory()
    {
        if (! is_null($this->viewFactory)) {
            return $this->viewFactory;
        }

        $application = new Application();

        $filesystem = new Filesystem();

        $bladeCompiler = new BladeCompiler(
            $filesystem,
            __DIR__ . '/../storage/views',
        );
        $application->instance('blade', $bladeCompiler);

        $resolver = new EngineResolver();

        $resolver->register('php', function () {
            return new PhpEngine();
        });

        $resolver->register('blade', function () use ($bladeCompiler) {
            return new CompilerEngine($bladeCompiler);
        });

        $factory = new \Illuminate\View\Factory(
            $resolver,
            $viewFinder = new FileViewFinder($filesystem, [
                __DIR__ . '/../../../framework/src/Themosis/Page/views/',
                __DIR__ . '/views/',
            ], ['blade.php', 'php']),
            new Dispatcher($application),
        );

        $factory->addExtension('blade', $resolver);
        $factory->setContainer($application);

        $this->viewFactory = $factory;

        return $factory;
    }

    protected function getValidationFactory($locale)
    {
        $translator = new Translator(new FileLoader(new Filesystem(), ''), $locale);

        return new Factory($translator, $this->getApplication());
    }

    public function getFactory($action)
    {
        return new PageFactory(
            $action,
            $this->getFilter(),
            $this->getViewFactory(),
            $this->getValidationFactory('en_US'),
        );
    }

    public function testCreateACustomPage()
    {
        $action = $this->getActionMock();
        $factory = $this->getFactory($action);

        $page = $factory->make('a-page', 'Custom Page');

        $this->assertInstanceOf(\Themosis\Page\Page::class, $page);
        $this->assertEquals('a-page', $page->getSlug());
        $this->assertEquals('Custom Page', $page->getTitle());
        $this->assertEquals('Custom Page', $page->getMenu());
        $this->assertEquals('manage_options', $page->getCapability());
        $this->assertEquals('dashicons-admin-generic', $page->getIcon());
        $this->assertEquals(21, $page->getPosition());
        $this->assertNull($page->getParent());
        $this->assertFalse($page->isNetwork());
        $this->assertInstanceOf(\Themosis\Page\PageView::class, $page->ui());
        $this->assertEquals('themosis.pages', $page->ui()->getTheme());
        $this->assertEquals('default', $page->ui()->getLayout());
        $this->assertEquals('page', $page->ui()->getViewPath());

        $action->expects($this->exactly(2))->method('add');

        $page->set();

        $this->assertEquals($page, $factory->getContainer()->make('page.' . $page->getSlug()));
    }

    public function testCreateANetworkPage()
    {
        $action = $this->getActionMock();
        $factory = $this->getFactory($action);

        $page = $factory->make('settings', 'Network Options')
            ->network();

        $this->assertTrue($page->isNetwork());

        $action->expects($this->exactly(2))->method('add');

        $page->set();
    }

    public function testCreatePageWithCustomView()
    {
        $factory = $this->getFactory($this->getActionStub());

        $page = $factory->make('custom', 'A Page');
        $page->ui()->setView('custom');
        $page->with('name', 'Marcel');
        $page->with('__page', $page);
        $page->with([
            'some' => 'value',
            'key' => 42,
        ]);
        $page->setCapability('custom_cap');
        $page->set();

        $this->assertEquals('custom', $page->ui()->getViewPath());
        $this->assertEquals('themosis.pages.default.custom', $page->ui()->getView()->name());
        $this->assertEquals('custom_cap', $page->getCapability());

        $view = $page->ui()->getView();
        $this->assertEquals('Marcel', $view['name']);
        $this->assertInstanceOf(\Themosis\Page\Page::class, $view['__page']);
        $this->assertEquals('value', $view['some']);
        $this->assertEquals(42, $view['key']);
    }

    public function testCreateASettingsPage()
    {
        $factory = $this->getFactory($this->getActionStub());
        $fieldFactory = new \Themosis\Field\Factory($this->getApplication(), $this->getViewFactory());

        $page = $factory->make('the-settings', 'App Settings')->set();

        $page->addSections([
            new Section('general', 'General'),
            (new Section('custom', 'Custom Section'))->setView('custom'),
        ]);

        $page->addSettings('general', [
            $firstname = $fieldFactory->text('firstname'),
            $email = $fieldFactory->email('email'),
        ]);

        $page->addSettings([
            'custom' => [
                $message = $fieldFactory->textarea('message'),
            ],
        ]);

        $this->assertInstanceOf(\Themosis\Page\PageSettingsRepository::class, $page->repository());
        $this->assertEquals(2, count($page->repository()->getSections()));

        $settings = $page->repository()->getSettings();

        $this->assertEquals(2, count($settings->keys()));
        $this->assertEquals(3, count($settings->collapse()->toArray()));

        $this->assertEquals($firstname, $page->repository()->getSettingByName('th_firstname'));
        $this->assertEquals('th_', $firstname->getPrefix());

        $page->setPrefix('xy_');

        $this->assertEquals('xy_', $email->getPrefix());
        $this->assertEquals('xy_', $message->getPrefix());
        $this->assertEquals('options', $page->ui()->getViewPath());

        $this->assertEquals('section', $page->repository()->getSectionByName('general')->getView());
        $this->assertEquals('custom', $page->repository()->getSectionByName('custom')->getView());
    }

    public function testCreateCustomPageUsingComposedViewPath()
    {
        $factory = $this->getFactory($this->getActionStub());

        $page = $factory->make('some-page', 'Awesome Page')
            ->setView('custom')
            ->set();

        $this->assertEquals('themosis.pages.default.custom', $page->ui()->getView()->name());
    }

    public function testCreateCustomPageUsingShortViewPath()
    {
        $factory = $this->getFactory($this->getActionStub());

        $page = $factory->make('the-page', 'Options')
            ->setView('pages.somepage', true)
            ->set();

        $this->assertEquals('pages.somepage', $page->ui()->getView()->name());
    }

    public function testPageParents()
    {
        $factory = $this->getFactory($this->getActionStub());

        $page = $factory->make('page', 'Page A')
            ->set();

        $this->assertFalse($page->hasParent());

        $page = $factory->make('page-b', 'Page B')
            ->setParent('general')
            ->set();

        $this->assertTrue($page->hasParent());
    }

    public function testPageCanShowSettingsInRest()
    {
        $factory = $this->getFactory($this->getActionStub());

        $page = $factory->make('some-page', 'Title');

        $this->assertFalse($page->isShownInRest());

        $page->showInRest();

        $this->assertTrue($page->isShownInRest());
    }

    public function testPostRoutesRegisterOnlyAuthenticatedActionHooks()
    {
        $action = $this->getActionMock();
        $page = $this->getFactory($action)->make('owned-page', 'Owned page');
        $hooks = [];
        $action->expects($this->exactly(5))->method('add')->willReturnCallback(function ($hook, $callback) use (&$hooks) {
            $hooks[$hook] = $callback;
        });
        $page->route('save', function ($argument) {}, 'post');
        $page->parsePostRoute();

        $this->assertSame('owned-page_save', $page->getAction('save'));
        $this->assertArrayHasKey('admin_post_owned-page_save', $hooks);
        $this->assertArrayNotHasKey('admin_post_nopriv_owned-page_save', $hooks);
        $this->assertIsCallable($hooks['admin_post_owned-page_save']);
    }

    public function testSanitizeSettingRetainsItsOneArgumentOverrideContract()
    {
        $method = new \ReflectionMethod(\Themosis\Page\Page::class, 'sanitizeSetting');
        $this->assertSame(1, $method->getNumberOfParameters());
        $this->assertSame(1, $method->getNumberOfRequiredParameters());
    }

    public function testFieldBoundSanitizerUsesSuppliedValueAndFieldTransformer()
    {
        $page = $this->getFactory($this->getActionStub())->make('owned-settings', 'Owned settings');
        $fields = new \Themosis\Field\Factory($this->getApplication(), $this->getViewFactory());
        $first = $fields->integer('first', ['rules' => 'required|integer|min:10']);
        $page->addSettings('general', [$first, $fields->text('second')]);
        $post = $_POST;
        $_POST = [$first->getName() => 'invalid'];

        try {
            $method = new \ReflectionMethod($page, 'sanitizeSettingValue');
            $this->assertSame('12', $method->invoke($page, $first, '12'));
            $this->assertSame('13', $method->invoke($page, $first, '13'));
        } finally {
            $_POST = $post;
        }
    }
}
