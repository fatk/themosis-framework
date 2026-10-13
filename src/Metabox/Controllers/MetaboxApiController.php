<?php

namespace Themosis\Metabox\Controllers;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Themosis\Metabox\Contracts\MetaboxManagerInterface;
use Themosis\Support\RequestOrigin;

class MetaboxApiController
{
    /**
     * @var MetaboxManagerInterface
     */
    protected $manager;

    public function __construct(MetaboxManagerInterface $manager)
    {
        $this->manager = $manager;
    }

    /**
     * GET /metabox/{id} API route
     *
     * @param Request $request
     * @param string  $id      The metabox unique ID.
     *
     * @return JsonResponse
     */
    public function show(Request $request, $id)
    {
        $abstract = sprintf('themosis.metabox.%s', $id);
        if (! app()->bound($abstract)) {
            throw new NotFoundHttpException();
        }

        try {
            $metabox = $this->manager->getFields(app($abstract), $request);
        } catch (\Exception $e) {
            if ($e instanceof HttpExceptionInterface || $e instanceof HttpResponseException) {
                throw $e;
            }
            throw new HttpResponseException(response()->json([
                'message' => $e->getMessage(),
                'errors' => true,
            ], 500));
        }

        return response()->json($metabox->toArray());
    }

    /**
     * PUT /metabox/{id} API route
     * Handle metabox fields update/save data.
     *
     * @param Request $request
     * @param string  $id      The metabox unique ID.
     *
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        $abstract = sprintf('themosis.metabox.%s', $id);
        if (! app()->bound($abstract)) {
            throw new NotFoundHttpException();
        }

        // Writes are cookie-authenticated without a token: refuse HTML form
        // method overrides and cross-site requests (CSRF).
        if ($request->getRealMethod() !== 'PUT' || RequestOrigin::isCrossSite($request)) {
            throw new AccessDeniedHttpException();
        }

        try {
            $metabox = $this->manager->saveFields(app($abstract), $request);
        } catch (\Exception $exception) {
            if ($exception instanceof HttpExceptionInterface || $exception instanceof HttpResponseException) {
                throw $exception;
            }
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => true,
            ], 500);
        }

        return response()->json($metabox->toArray());
    }
}
