<?php

namespace Themosis\Tests\Metabox;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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
                (new MetaboxApiController($manager))->{$action}(Request::create('/', $action === 'update' ? 'PUT' : 'GET'), 'restricted');
                self::fail('A deliberate HTTP denial must propagate.');
            } catch (NotFoundHttpException $exception) {
                self::assertSame($denial, $exception);
            }
        } finally {
            Application::setInstance($previous);
        }
    }

    public static function forgeries(): array
    {
        return [
            'method override from a form post' => [Request::create('/', 'POST', ['_method' => 'PUT']), false],
            'cross-site put' => [Request::create('/', 'PUT', [], [], [], ['HTTP_SEC_FETCH_SITE' => 'cross-site']), false],
            'same-origin put' => [Request::create('/', 'PUT', [], [], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']), true],
            'put without fetch metadata' => [Request::create('/', 'PUT'), true],
        ];
    }

    #[DataProvider('forgeries')]
    public function testUpdateRequiresARealSameSitePut(Request $request, bool $allowed): void
    {
        $previous = Application::getInstance();
        $app = new Application();
        $response = $this->createStub(\Illuminate\Contracts\Routing\ResponseFactory::class);
        $response->method('json')->willReturn(new \Illuminate\Http\JsonResponse([], 200));
        $app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, $response);
        $box = $this->createStub(\Themosis\Metabox\Contracts\MetaboxInterface::class);
        $box->method('toArray')->willReturn([]);
        $app->instance('themosis.metabox.owned', $box);
        $manager = $this->createMock(MetaboxManagerInterface::class);
        $manager->expects($allowed ? self::once() : self::never())->method('saveFields')->willReturn($box);
        try {
            try {
                (new MetaboxApiController($manager))->update($request, 'owned');
                self::assertTrue($allowed, 'A forged update must be denied.');
            } catch (AccessDeniedHttpException $exception) {
                self::assertFalse($allowed);
                self::assertSame(403, $exception->getStatusCode());
            }
        } finally {
            Application::setInstance($previous);
        }
    }

    public static function debugModes(): array
    {
        return [
            'show production' => ['show', false, 'Server Error'],
            'update production' => ['update', false, 'Server Error'],
            'show debug' => ['show', true, '/secret/path failed'],
            'update debug' => ['update', true, '/secret/path failed'],
        ];
    }

    #[DataProvider('debugModes')]
    public function testUnexpectedErrorMessagesAreOnlyExposedInDebugMode(string $action, bool $debug, string $message): void
    {
        $previous = Application::getInstance();
        $app = new Application();
        $app->instance('config', new \Illuminate\Config\Repository(['app' => ['debug' => $debug]]));
        $captured = null;
        $response = $this->createStub(\Illuminate\Contracts\Routing\ResponseFactory::class);
        $response->method('json')->willReturnCallback(function ($data, $status) use (&$captured) {
            $captured = $data;

            return new \Illuminate\Http\JsonResponse($data, $status);
        });
        $app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, $response);
        $app->instance('themosis.metabox.owned', $this->createStub(\Themosis\Metabox\Contracts\MetaboxInterface::class));
        $manager = $this->createStub(MetaboxManagerInterface::class);
        $manager->method($action === 'show' ? 'getFields' : 'saveFields')->willThrowException(new \RuntimeException('/secret/path failed'));
        try {
            try {
                (new MetaboxApiController($manager))->{$action}(Request::create('/', $action === 'update' ? 'PUT' : 'GET'), 'owned');
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
                // show() throws the JSON response.
            }
            self::assertSame($message, $captured['message']);
        } finally {
            Application::setInstance($previous);
        }
    }
}
