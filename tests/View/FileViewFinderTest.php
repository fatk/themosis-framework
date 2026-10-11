<?php

namespace Themosis\Tests\View;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Themosis\View\FileViewFinder;

class FileViewFinderTest extends TestCase
{
    public function test_ordered_paths_remain_stable_and_keep_registration_order()
    {
        $finder = new FileViewFinder(new Filesystem(), ['base']);
        $finder->addOrderedLocation('first', 1);
        $finder->addOrderedLocation('second', 10);
        $finder->addOrderedLocation('third', 10);
        $expected = ['first', 'second', 'third', 'base'];

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($expected, $finder->getPaths());
            $this->assertCount(4, $finder->getPaths());
        }

        $finder->addLocation('later');
        $finder->prependLocation('prepended');
        $finder->addOrderedLocation('highest', 0);
        $this->assertSame(['highest', 'first', 'second', 'third', 'prepended', 'base', 'later'], $finder->getPaths());
        $finder->setPaths(['replacement']);
        $this->assertSame(['highest', 'first', 'second', 'third', 'replacement'], $finder->getPaths());
    }

    public function test_missing_and_new_views_do_not_accumulate_paths()
    {
        $files = new Filesystem();
        $directory = sys_get_temp_dir().'/themosis-view-'.uniqid();
        $files->makeDirectory($directory.'/ordered', 0755, true);
        $files->makeDirectory($directory.'/base');
        $directory = realpath($directory);
        $finder = new FileViewFinder($files, [$directory.'/base']);
        $finder->addOrderedLocation($directory.'/ordered', 1);
        $expected = [$directory.'/ordered', $directory.'/base'];

        try {
            for ($i = 0; $i < 3; $i++) {
                try {
                    $finder->find('missing');
                    $this->fail('A missing view must throw.');
                } catch (\InvalidArgumentException $exception) {
                    $this->assertSame('View [missing] not found.', $exception->getMessage());
                }
                $this->assertSame($expected, $finder->getPaths());
            }

            $files->put($directory.'/ordered/missing.blade.php', 'ordered');
            $files->put($directory.'/base/missing.blade.php', 'base');
            $this->assertSame($directory.'/ordered/missing.blade.php', $finder->find('missing'));
            $files->put($directory.'/base/new.php', 'new');
            $this->assertSame($directory.'/base/new.php', $finder->find('new'));
            $finder->addNamespace('base', $directory.'/base');
            $this->assertSame($directory.'/base/missing.blade.php', $finder->find('base::missing'));
            $this->assertSame($expected, $finder->getPaths());

            $plain = new FileViewFinder($files, [$directory.'/base']);
            $plain->prependLocation($directory.'/ordered');
            $this->assertSame($expected, $plain->getPaths());
            $this->assertSame($directory.'/ordered/missing.blade.php', $plain->find('missing'));
            $this->assertSame($directory.'/base/new.php', $plain->find('new'));
            $this->assertSame($expected, $plain->getPaths());
        } finally {
            $files->deleteDirectory($directory);
        }
    }

    public function test_add_view_location_with_priority()
    {
        $finder = new FileViewFinder(new Filesystem(), []);

        $finder->addOrderedLocation('first/resources', 1);
        $finder->addLocation('root/resources');
        $finder->prependLocation('plugin/resources');
        $finder->addOrderedLocation('child/resources', 10);
        $finder->prependLocation('theme/resources');

        $this->assertEquals('first/resources', $finder->getPaths()[0]);
        $this->assertEquals('child/resources', $finder->getPaths()[1]);
    }
}
