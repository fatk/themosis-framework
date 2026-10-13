<?php

namespace Themosis\Tests\View;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Themosis\Core\Application;
use Themosis\View\FileViewFinder;
use Themosis\View\ViewServiceProvider;

class TwigEnvironmentTest extends TestCase
{
    public static function configurations(): array
    {
        return [
            'production' => [['debug' => false], [], false],
            'debug' => [['debug' => true], [], true],
            'explicit override' => [['debug' => false], ['twig_auto_reload' => true], true],
        ];
    }

    #[DataProvider('configurations')]
    public function testAutoReloadFollowsDebugUnlessConfigured(array $app, array $view, bool $expected): void
    {
        $previous = Container::getInstance();
        try {
            $container = new Application();
            $container->instance('config', new Repository(['app' => $app, 'view' => $view + ['twig' => false]]));
            $container->instance('view.finder', new FileViewFinder(new Filesystem(), []));
            $provider = new ViewServiceProvider($container);
            $provider->registerTwigLoader();
            $provider->registerTwigEnvironment();

            self::assertSame($expected, $container['twig']->isAutoReload());
        } finally {
            Container::setInstance($previous);
        }
    }
}
