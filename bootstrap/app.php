<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureClientMatchesUserType;
use App\Http\Middleware\EnsureUserType;
use App\Http\Middleware\SetLocaleFromHeader;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException as PermissionUnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Pusher private channels need an auth endpoint, and Laravel's default one sits at
    // `/broadcasting/auth` on the `web` guard. No client of this API ever holds a web
    // session (D-69: the dashboards keep the bearer token server-side, the apps send it
    // as a header), so the stock route would answer 403 for every subscriber. Registered
    // here on `auth:sanctum`, under the same `api/v1` prefix as everything else, so the
    // dashboards can reach it through the BFF and the apps with their own token.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        attributes: ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'client.type' => EnsureClientMatchesUserType::class,
            // D-73 — applied beside `client.type` on every authenticated group, for
            // the same reason it is: it reads `$request->user()`, so it has to run
            // after `auth:sanctum` and can never be global API middleware.
            'account.active' => EnsureAccountIsActive::class,
            'type' => EnsureUserType::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // NOTE: EnsureClientMatchesUserType reads $request->user(), so it must run
        // AFTER auth:sanctum resolves the token — never as global/prepended API
        // middleware, which always runs before route-level middleware regardless
        // of prepend/append. It is applied per-route instead, as 'client.type'.
        $middleware->api(prepend: [
            SetLocaleFromHeader::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (ApiException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->errorCode, $e->getMessage(), $e->status, $e->errors, $e->meta);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('validation_failed', $e->getMessage(), 422, $e->errors());
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('unauthenticated', 'يجب تسجيل الدخول', 401);
            }
        });

        $exceptions->render(function (PermissionUnauthorizedException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('forbidden', 'ليس لديك الصلاحية للقيام بهذا الإجراء', 403);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('forbidden', 'غير مصرح لك بهذا الإجراء', 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('not_found', 'العنصر غير موجود', 404);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('not_found', 'المسار غير موجود', 404);
            }
        });
    })->create();
