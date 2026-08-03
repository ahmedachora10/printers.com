<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Handle 403 - Access Denied (Gate::authorize throws AccessDeniedHttpException;
        // Laratrust middleware calls abort(403) which throws a generic HttpException)
        $exceptions->render(function (HttpException $e, $request) {

            if ($e->getStatusCode() !== 403) {
                return null;
            }

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'ليس لديك الصلاحية للوصول إلى هذا المورد',
                    'status' => 403,
                ], 403);
            }

            // Handle Inertia requests - render custom 403 page
            return Inertia::render('errors/403')
                ->toResponse($request)
                ->setStatusCode(403);
        });

        // Handle 404 - Not Found
        $exceptions->render(function (NotFoundHttpException $e, $request) {

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'الصفحة غير موجودة',
                    'status' => 404,
                ], 404);
            }

            // Handle Inertia requests - render custom 404 page
            return Inertia::render('errors/404')
                ->toResponse($request)
                ->setStatusCode(404);
        });
    })->create();
