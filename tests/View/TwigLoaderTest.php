<?php

namespace Themosis\Tests\View;

use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Themosis\View\FileViewFinder;
use Themosis\View\ViewServiceProvider;
use Twig\Environment;
use Twig\Error\LoaderError;

class TwigLoaderTest extends TestCase
{
    private $directory;
    private $files;
    private $finder;
    private $loader;

    protected function setUp(): void
    {
        $this->files = new Filesystem();
        $this->directory = sys_get_temp_dir().'/themosis-twig-'.uniqid();
        foreach (['base/nested', 'theme/nested', 'late'] as $path) {
            $this->files->makeDirectory($this->directory.'/'.$path, 0755, true);
        }
        $this->directory = realpath($this->directory);
        $this->finder = new FileViewFinder($this->files, [$this->directory.'/base'], ['twig']);
        $app = new Container();
        $app->instance('view.finder', $this->finder);
        (new ViewServiceProvider($app))->registerTwigLoader();
        $this->loader = $app['twig.loader'];
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->directory);
    }

    public function test_late_theme_locations_resolve_overrides_and_unique_templates()
    {
        $this->files->put($this->directory.'/base/page.twig', 'base');
        $this->files->put($this->directory.'/theme/page.twig', 'theme');
        $this->files->put($this->directory.'/theme/unique.twig', 'unique');
        $this->finder->addOrderedLocation($this->directory.'/theme', 10);

        $this->assertSame($this->finder->find('page'), $this->loader->getSourceContext('page.twig')->getPath());
        $this->assertSame('unique', $this->loader->getSourceContext('unique.twig')->getCode());
        $this->assertSame($this->finder->getPaths(), $this->loader->getPaths());
    }

    public function test_namespaces_and_name_aliases_share_the_selected_file_and_cache_key()
    {
        $this->files->put($this->directory.'/theme/nested/page.twig', 'theme');
        $this->finder->addNamespace('theme', $this->directory.'/theme');
        $path = $this->finder->find('theme::nested.page');

        foreach (['theme::nested.page', 'theme::nested/page.twig', '@theme/nested/page.twig', $path] as $name) {
            $this->assertSame($path, $this->loader->getSourceContext($name)->getPath());
            $this->assertSame($path, $this->loader->getCacheKey($name));
        }
    }

    public function test_compiled_templates_follow_finder_cache_and_flush_for_nested_templates()
    {
        $this->files->put($this->directory.'/base/page.twig', '{% extends "layout.twig" %}{% block content %}{% include "nested/part.twig" %}{% endblock %}');
        $this->files->put($this->directory.'/base/layout.twig', 'base[{% block content %}{% endblock %}]');
        $this->files->put($this->directory.'/base/nested/part.twig', 'base');
        $this->files->put($this->directory.'/theme/layout.twig', 'theme[{% block content %}{% endblock %}]');
        $this->files->put($this->directory.'/theme/nested/part.twig', 'theme');
        $twig = new Environment($this->loader, ['cache' => $this->directory.'/compiled']);
        $this->assertSame('base[base]', $twig->render('page.twig'));
        $key = $this->loader->getCacheKey('layout.twig');
        $this->finder->addOrderedLocation($this->directory.'/theme', 10);
        $this->assertSame('base[base]', $twig->render('page.twig'));
        $this->finder->flush();
        $this->assertNotSame($key, $this->loader->getCacheKey('layout.twig'));
        $this->assertSame('theme[theme]', $twig->render('page.twig'));
        $this->assertSame('theme[theme]', (new Environment($this->loader, ['cache' => $this->directory.'/compiled']))->render('page.twig'));
    }

    public function test_loader_path_registration_updates_the_shared_finder()
    {
        $this->files->put($this->directory.'/theme/page.twig', 'theme');
        $this->loader->addPath($this->directory.'/theme');
        $this->assertSame($this->directory.'/theme/page.twig', $this->finder->find('page'));
        $this->loader->addPath($this->directory.'/theme', 'theme');
        $this->assertSame([$this->directory.'/theme'], $this->loader->getPaths('theme'));
        $this->assertSame($this->finder->find('theme::page'), $this->loader->getSourceContext('@theme/page.twig')->getPath());
    }

    public function test_missing_templates_can_appear_in_late_locations()
    {
        $this->assertFalse($this->loader->exists('new.twig'));
        $this->files->put($this->directory.'/late/new.twig', 'late');
        $this->finder->addLocation($this->directory.'/late');
        $this->assertTrue($this->loader->exists('new.twig'));
        $this->assertFalse($this->loader->isFresh('new.twig', 1));
        $this->assertTrue($this->loader->isFresh('new.twig', time() + 10));
    }

    public function test_same_name_top_level_cache_key_changes_after_finder_flush()
    {
        $this->files->put($this->directory.'/base/page.twig', 'base');
        $this->files->put($this->directory.'/theme/page.twig', 'theme');
        $twig = new Environment($this->loader, ['cache' => $this->directory.'/compiled']);
        $this->assertSame('base', $twig->render('page.twig'));
        $this->finder->addOrderedLocation($this->directory.'/theme', 10);
        $this->assertSame('base', $twig->render('page.twig'));
        $this->finder->flush();
        $this->assertSame($this->finder->find('page'), $this->loader->getCacheKey('page.twig'));
        $this->assertSame('theme', $twig->render('page.twig'));
    }

    public function test_invalid_and_missing_namespace_names_do_not_exist()
    {
        foreach (["bad\0.twig", '../page.twig', '@broken', '@unknown/page.twig'] as $name) {
            $this->assertFalse($this->loader->exists($name));
        }
    }

    public function test_path_registration_rejects_missing_directories()
    {
        $this->expectException(LoaderError::class);
        $this->loader->addPath($this->directory.'/missing');
    }

    public function test_non_view_files_resolve_by_relative_path_with_theme_precedence()
    {
        $this->files->makeDirectory($this->directory.'/base/svg/icons', 0755, true);
        $this->files->makeDirectory($this->directory.'/theme/svg/icons', 0755, true);
        $this->files->put($this->directory.'/base/svg/icons/close.svg', '<svg>base</svg>');
        $this->files->put($this->directory.'/base/svg/icons/open.svg', '<svg>open</svg>');
        $this->files->put($this->directory.'/theme/svg/icons/close.svg', '<svg>theme</svg>');
        $this->files->put($this->directory.'/base/page.twig', '{% include "svg/icons/close.svg" %}{{ source("svg/icons/open.svg") }}{% include "svg/missing.svg" ignore missing %}');
        $this->finder->addOrderedLocation($this->directory.'/theme', 10);
        $twig = new Environment($this->loader, ['cache' => $this->directory.'/compiled']);

        $this->assertSame('<svg>theme</svg><svg>open</svg>', $twig->render('page.twig'));
        $this->assertSame($this->directory.'/theme/svg/icons/close.svg', $this->loader->getCacheKey('svg/icons/close.svg'));
        $this->assertFalse($this->loader->exists('svg/missing.svg'));
    }

    public function test_namespaced_non_view_files_resolve_by_relative_path()
    {
        $this->files->makeDirectory($this->directory.'/theme/svg', 0755, true);
        $this->files->put($this->directory.'/theme/svg/logo.svg', '<svg>logo</svg>');
        $this->finder->addNamespace('theme', $this->directory.'/theme');

        $this->assertSame('<svg>logo</svg>', $this->loader->getSourceContext('@theme/svg/logo.svg')->getCode());
        $this->assertFalse($this->loader->exists('@theme/svg/missing.svg'));
        $this->assertFalse($this->loader->exists('../base/page.twig'));
    }

    public function test_lookups_are_memoized_until_the_finder_changes()
    {
        $finder = new class($this->files, [$this->directory.'/base'], ['twig']) extends FileViewFinder {
            public array $finds = [];
            public function find($name)
            {
                $this->finds[] = $name;

                return parent::find($name);
            }
        };
        $loader = new \Themosis\View\TwigLoader($finder);
        $this->files->makeDirectory($this->directory.'/base/svg', 0755, true);
        $this->files->put($this->directory.'/base/svg/icon.svg', '<svg/>');
        $this->files->put($this->directory.'/base/part.twig', 'part');
        $this->files->put($this->directory.'/base/page.twig', str_repeat('{% include "svg/icon.svg" %}{% include "part.twig" %}', 10));
        $twig = new Environment($loader, ['cache' => $this->directory.'/compiled']);

        $this->assertSame(str_repeat('<svg/>part', 10), $twig->render('page.twig'));
        $this->assertSame(['page', 'part'], array_values(array_unique($finder->finds)));
        $this->assertCount(2, $finder->finds, 'Each view is looked up once; non-view files skip the finder.');

        // Path changes and flushes invalidate both found and missing results.
        $this->assertFalse($loader->exists('late.twig'));
        $this->files->put($this->directory.'/late/late.twig', 'late');
        $finder->addLocation($this->directory.'/late');
        $this->assertTrue($loader->exists('late.twig'));
        $this->files->put($this->directory.'/theme/part.twig', 'theme');
        $finder->addOrderedLocation($this->directory.'/theme', 10);
        $finder->flush();
        $this->assertSame($this->directory.'/theme/part.twig', $loader->getCacheKey('part.twig'));
    }

    public function test_absolute_paths_must_be_inside_view_locations()
    {
        $this->files->put($this->directory.'/base/page.twig', 'page');
        $this->files->put($this->directory.'/theme/ns.twig', 'ns');
        $this->files->put($this->directory.'/outside.twig', 'outside');
        $this->finder->addNamespace('theme', $this->directory.'/theme');

        $this->assertSame('page', $this->loader->getSourceContext($this->directory.'/base/page.twig')->getCode());
        $this->assertSame('ns', $this->loader->getSourceContext($this->directory.'/theme/ns.twig')->getCode());
        $this->assertFalse($this->loader->exists($this->directory.'/outside.twig'));
        $this->assertFalse($this->loader->exists($this->directory.'/base/../outside.twig'));
        $this->assertFalse($this->loader->exists('/etc/hosts'));

        $twig = new Environment($this->loader);
        $this->files->put($this->directory.'/base/leak.twig', '{{ source("/etc/hosts") }}');
        $this->expectException(LoaderError::class);
        $twig->render('leak.twig');
    }

    public function test_missing_templates_raise_twig_loader_errors()
    {
        $this->expectException(LoaderError::class);
        $this->loader->getSourceContext('missing.twig');
    }
}
