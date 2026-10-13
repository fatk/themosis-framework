<?php

namespace Themosis\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Themosis\Support\RequestOrigin;

class RequestOriginTest extends TestCase
{
    public static function requests(): array
    {
        return [
            'cross-site fetch metadata' => [['HTTP_SEC_FETCH_SITE' => 'cross-site'], true],
            'cross-site metadata wins over matching origin' => [['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://example.test'], true],
            'same-origin fetch metadata' => [['HTTP_SEC_FETCH_SITE' => 'same-origin'], false],
            'same-site fetch metadata' => [['HTTP_SEC_FETCH_SITE' => 'same-site'], true],
            'unknown fetch metadata' => [['HTTP_SEC_FETCH_SITE' => 'bogus'], true],
            'user-initiated navigation' => [['HTTP_SEC_FETCH_SITE' => 'none'], false],
            'foreign origin without metadata' => [['HTTP_ORIGIN' => 'https://attacker.test'], true],
            'matching origin without metadata' => [['HTTP_ORIGIN' => 'https://EXAMPLE.test'], false],
            'opaque origin without metadata' => [['HTTP_ORIGIN' => 'null'], false],
            'legacy client without headers' => [[], false],
        ];
    }

    #[DataProvider('requests')]
    public function testCrossSiteDetection(array $server, bool $expected): void
    {
        $request = Request::create('https://example.test/cms/wp-admin/admin-post.php', 'POST', [], [], [], $server);

        self::assertSame($expected, RequestOrigin::isCrossSite($request));
    }

    public function testDefaultsToTheGlobalRequest(): void
    {
        $server = $_SERVER;

        try {
            $_SERVER['HTTP_HOST'] = 'example.test';
            $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
            self::assertTrue(RequestOrigin::isCrossSite());
        } finally {
            $_SERVER = $server;
        }
    }
}
