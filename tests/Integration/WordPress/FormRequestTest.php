<?php

namespace Themosis\Tests\Integration\WordPress;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class FormRequestTest extends WordPressTestCase
{
    public static function methods(): array
    {
        return [['post'], ['POST'], ['Post']];
    }

    #[DataProvider('methods')]
    public function testPostFormsRenderTheNonceRegardlessOfMethodCase(string $method): void
    {
        $form = $this->form();
        $form->setAttributes(['method' => $method]);

        self::assertStringContainsString('name="_themosisnonce"', $form->render());
    }

    public function testGetFormsDoNotRenderTheNonce(): void
    {
        $form = $this->form();
        $form->setAttributes(['method' => 'get']);

        self::assertStringNotContainsString('_themosisnonce', $form->render());
    }

    public static function submissions(): array
    {
        return [
            'cross-site post' => ['POST', ['HTTP_SEC_FETCH_SITE' => 'cross-site'], false],
            'foreign origin post' => ['POST', ['HTTP_ORIGIN' => 'https://attacker.invalid'], false],
            'same-origin post' => ['POST', ['HTTP_SEC_FETCH_SITE' => 'same-origin'], true],
            'post without fetch metadata' => ['POST', [], true],
            'cross-site get' => ['GET', ['HTTP_SEC_FETCH_SITE' => 'cross-site'], true],
        ];
    }

    #[DataProvider('submissions')]
    public function testCrossSiteSubmissionsAreRejected(string $method, array $server, bool $handled): void
    {
        $form = $this->form();
        $request = Request::create('https://' . parse_url(home_url(), PHP_URL_HOST) . '/contact', $method, ['th_name' => 'value'], [], [], $server);

        try {
            $form->handleRequest($request);
            self::assertTrue($handled, 'A cross-site submission must be rejected.');
            self::assertSame('value', $form->repository()->getFieldByName('name')->getValue());
        } catch (AccessDeniedHttpException $exception) {
            self::assertFalse($handled);
        }
    }

    private function form()
    {
        return app('form')->make()->add(app('field')->text('name'))->get();
    }
}
