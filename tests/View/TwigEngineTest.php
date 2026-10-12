<?php

namespace Themosis\Tests\View;

use Composer\Autoload\ClassLoader;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use PHPUnit\Framework\TestCase;
use Themosis\Core\Application;
use Themosis\Core\ThemeManager;
use Themosis\View\FileViewFinder;
use Themosis\View\ViewServiceProvider;
use Twig\Environment;

class TwigEngineTest extends TestCase
{
    public function test_factory_aliases_namespaces_and_physical_paths_render_the_selected_source()
    {
        $files = new Filesystem();
        $directory = sys_get_temp_dir().'/themosis-twig-engine-'.uniqid();
        $files->makeDirectory($directory.'/base', 0755, true);
        $files->makeDirectory($directory.'/theme');
        $directory = realpath($directory);
        $previousContainer = Container::getInstance();
        try {
            $files->put($directory.'/base/page.twig', 'base');
            $files->put($directory.'/theme/page.twig', 'theme {% include "part.twig" %}');
            $files->put($directory.'/theme/part.twig', '{{ value }}');
            foreach (['page', 'part'] as $name) {
                $files->put($directory.'/theme/'.$name.'.blade.php', 'blade');
                $files->put($directory.'/theme/'.$name.'.php', 'php');
            }
            $finder = new FileViewFinder($files, [$directory.'/base']);
            $app = new Application();
            $app->instance('view.finder', $finder);
            (new ViewServiceProvider($app))->registerTwigLoader();
            $app->instance('twig', new Environment($app['twig.loader']));
            $resolver = new EngineResolver();
            $factory = new Factory($resolver, $finder, new Dispatcher());
            $app->instance('view', $factory);
            (new ViewServiceProvider($app))->registerTwigEngine();
            (new ThemeManager($app, $directory, new ClassLoader()))->views(['theme' => 10]);
            $this->assertSame([$directory.'/theme', $directory.'/base'], $finder->getPaths());
            $finder->addNamespace('theme', $directory.'/theme');
            $this->assertSame($directory.'/theme/page.twig', $finder->find('page'));
            $this->assertSame($directory.'/theme/part.twig', $app['twig.loader']->getSourceContext('part.twig')->getPath());

            $this->assertSame('theme selected', $factory->make('page', ['value' => 'selected'])->render());
            $this->assertSame('theme named', $factory->make('theme::page', ['value' => 'named'])->render());
            $finder->flush();
            $this->assertSame('theme physical', $factory->file($directory.'/theme/page.twig', ['value' => 'physical'])->render());
        } finally {
            Container::setInstance($previousContainer);
            $files->deleteDirectory($directory);
        }
        $this->assertSame($previousContainer, Container::getInstance());
    }
}
