<?php

namespace Themosis\Tests\Metabox;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Themosis\Core\Application;
use Themosis\Metabox\Contracts\MetaboxManagerInterface;
use Themosis\Metabox\Controllers\MetaboxApiController;

class MetaboxApiControllerTest extends TestCase
{
    public static function actions(): array
    {
        return [['show'], ['update']];
    }

    #[DataProvider('actions')]
    public function testUnknownRegistrationIsConcealedWithoutResolvingIt(string $action): void
    {
        $previous = Application::getInstance();
        $app = new Application();
        $response = $this->createStub(\Illuminate\Contracts\Routing\ResponseFactory::class);
        $response->method('json')->willReturn(new \Illuminate\Http\JsonResponse([], 500));
        $app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, $response);
        $resolved = false;
        $app->beforeResolving('themosis.metabox.unknown', function () use (&$resolved) {
            $resolved = true;
        });
        $manager = $this->createMock(MetaboxManagerInterface::class);
        $manager->expects(self::never())->method('getFields');
        $manager->expects(self::never())->method('saveFields');
        try {
            try {
                (new MetaboxApiController($manager))->{$action}(Request::create('/'), 'unknown');
                self::fail('An unknown registration must return 404.');
            } catch (NotFoundHttpException $exception) {
                self::assertSame(404, $exception->getStatusCode());
                self::assertFalse($resolved);
            }
        } finally {
            Application::setInstance($previous);
        }
    }

    #[DataProvider('actions')]
    public function testDeliberateHttpDenialIsPreserved(string $action): void
    {
        $previous = Application::getInstance();
        $app = new Application();
        $response = $this->createStub(\Illuminate\Contracts\Routing\ResponseFactory::class);
        $response->method('json')->willReturn(new \Illuminate\Http\JsonResponse([], 500));
        $app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, $response);
        $box = $this->createStub(\Themosis\Metabox\Contracts\MetaboxInterface::class);
        $app->instance('themosis.metabox.restricted', $box);
        $manager = $this->createMock(MetaboxManagerInterface::class);
        $denial = new NotFoundHttpException();
        $manager->expects(self::once())->method($action === 'show' ? 'getFields' : 'saveFields')->willThrowException($denial);
        try {
            try {
                (new MetaboxApiController($manager))->{$action}(Request::create('/'), 'restricted');
                self::fail('A deliberate HTTP denial must propagate.');
            } catch (NotFoundHttpException $exception) {
                self::assertSame($denial, $exception);
            }
        } finally {
            Application::setInstance($previous);
        }
    }
}
