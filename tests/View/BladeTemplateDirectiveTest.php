<?php

namespace Themosis\Tests\View;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\TestCase;
use Themosis\View\BladeServiceProvider;

class BladeTemplateDirectiveTest extends TestCase
{
    private string $directory;
    private BladeCompiler $compiler;
    private Factory $views;
    private $previousFacadeApplication;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/themosis-template-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $files = new Filesystem();
        $this->compiler = new BladeCompiler($files, $this->directory);
        $app = new Container();
        $app->instance('blade.compiler', $this->compiler);
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        Facade::clearResolvedInstance('blade.compiler');
        Facade::setFacadeApplication($app);
        (new BladeServiceProvider($app))->boot();
        $engines = new EngineResolver();
        $engines->register('php', fn () => new PhpEngine($files));
        $this->views = new Factory($engines, new FileViewFinder($files, [$this->directory]), new Dispatcher($app));
        foreach (['content' => 'fallback', 'content-post' => 'post', 'content-page' => 'page', 'content-strtolower' => 'literal'] as $name => $label) {
            file_put_contents($this->directory . '/' . $name . '.php', $label . '|<?= $inherited ?>|<?= $first ?? "" ?>|<?= $nested["value"] ?? "" ?>|<?= $last ?? "" ?>');
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
        Facade::clearResolvedInstance('blade.compiler');
        Facade::setFacadeApplication($this->previousFacadeApplication);
    }

    private function render(string $compiled, array $variables): string
    {
        $__env = $this->views;
        extract($variables);
        ob_start();
        try {
            eval('?>' . $compiled);
            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    public function test_compiled_template_evaluates_current_selector_each_time(): void
    {
        $calls = 0;
        $selector = function () use (&$calls) { ++$calls; return 'post'; };
        $compiled = $this->compiler->compileString('@template("content", $selector())');
        $this->assertSame(0, $calls);
        $this->assertSame('post|parent|||', $this->render($compiled, ['selector' => $selector, 'inherited' => 'parent']));
        $this->assertSame(1, $calls);
        $this->assertSame('page|next|||', $this->render($compiled, ['selector' => fn () => 'page', 'inherited' => 'next']));
        $this->assertSame('fallback|third|||', $this->render($compiled, ['selector' => fn () => 'missing', 'inherited' => 'third']));
    }

    public function test_runtime_arguments_preserve_nested_data_and_quoted_commas(): void
    {
        $compiled = $this->compiler->compileString('@template($base, $type, ["first" => "one,two", "nested" => ["value" => implode(",", ["three", "four"])], "last" => $last, "inherited" => "override"])');
        $this->assertSame('page|override|one,two|three,four|five', $this->render($compiled, ['base' => 'content', 'type' => 'page', 'inherited' => 'parent', 'last' => 'five']));
        $this->assertSame('fallback|override|one,two|three,four|six', $this->render($compiled, ['base' => 'content', 'type' => 'missing', 'inherited' => 'parent', 'last' => 'six']));
    }

    public function test_static_selector_is_a_literal_even_when_callable(): void
    {
        $compiled = $this->compiler->compileString('@template("content", "strtolower")');
        $this->assertSame('literal|parent|||', $this->render($compiled, ['inherited' => 'parent']));
    }

    public function test_base_template_inherits_variables_without_selector(): void
    {
        $compiled = $this->compiler->compileString('@template("content")');
        $this->assertSame('fallback|parent|||', $this->render($compiled, ['inherited' => 'parent']));
    }
}
