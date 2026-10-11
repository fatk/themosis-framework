<?php

namespace Themosis\Tests\Integration\WordPress;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MultisiteRouteUrlTest extends TestCase
{
    public static function sites(): array
    {
        return [['main'], ['child']];
    }

    #[DataProvider('sites')]
    public function testNativeMultisiteNamedUrls(string $mode): void
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/route-url.php', $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($mode, $result['mode']);
        self::assertSame($mode === 'main' ? '/network/' : '/network/child/', $result['site_path']);
        self::assertSame($result['expected'], $result['actual']);
        self::assertSame('/probe/12?q=yes', $result['relative']);
        self::assertSame('https://multisite-audit.invalid/probe', $result['root']);
        self::assertSame('https://alpha.mapped.example/probe/12', $result['domain']);
        self::assertSame($result['forced_expected'], $result['forced_actual']);
    }
}
