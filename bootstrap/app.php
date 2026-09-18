<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureClientMatchesUserType;
use App\Http\Middleware\EnsureGlobalAdminScope;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureUserType;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocaleFromHeader;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException as PermissionUnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
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
        // Batch 67 — the same three checks every other authenticated route runs: a
        // blocked account, or a token presented from the wrong client, could subscribe
        // to a private channel because this route carried `auth:sanctum` alone.
        attributes: ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum', 'client.type', 'account.active', 'password.fresh']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'client.type' => EnsureClientMatchesUserType::class,
            // D-73 — applied beside `client.type` on every authenticated group, for
            // the same reason it is: it reads `$request->user()`, so it has to run
            // after `auth:sanctum` and can never be global API middleware.
            'account.active' => EnsureAccountIsActive::class,
            'type' => EnsureUserType::class,
            // Batch 67 — a temporary password opens the password change and nothing
            // else, for the three account types the office creates. Beside
            // `account.active` on every authenticated group.
            'password.fresh' => EnsurePasswordChanged::class,
            // Batch 67 — doc 12 §10.1: users, roles, settings and the audit log are
            // global-only whatever the role grants. 404 for a scoped admin.
            'admin.global' => EnsureGlobalAdminScope::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // NOTE: EnsureClientMatchesUserType reads $request->user(), so it must run
        // AFTER auth:sanctum resolves the token — never as global/prepended API
        // middleware, which always runs before route-level middleware regardless
        // of prepend/append. It is applied per-route instead, as 'client.type'.
        $middleware->api(prepend: [
            // Batch 67 — first, so every later middleware (and `auth:sanctum`'s guest
            // path in particular) sees a request that expects JSON.
            ForceJsonResponse::class,
            SetLocaleFromHeader::class,
        ]);

        // Batch 67 — every response from this origin, including the ones no route
        // produced (404, 405) and the exception pages. See the class.
        $middleware->append(SecurityHeaders::class);

        // Batch 67 — an unauthenticated API request is a 401, never a redirect to a
        // `login` route this API does not have (that redirect was the production 500).
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Batch 67 — an `/api/*` request is answered in JSON whatever it sent in
        // `Accept`, including the answers rendered before any route middleware ran
        // (a path that does not exist, a verb the path does not take).
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

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

        /*
         * A FormRequest whose `authorize()` answers false throws
         * `AuthorizationException` — and this closure never sees one.
         *
         * Laravel's handler runs `prepareException()` **before**
         * `renderViaCallbacks()`, and that method rewrites a status-less
         * `AuthorizationException` into Symfony's `AccessDeniedHttpException`.
         * By the time the callbacks are consulted the class registered here no
         * longer matches, so the refusal fell through to the framework's own
         * renderer: no `success` key, no `error` code, an English
         * «This action is unauthorized.», and — with `APP_DEBUG` on — the
         * server's absolute paths and a full stack trace in the body.
         *
         * That is **27 requests** across the API (`Update*Request`,
         * `Store*Request` — every one that gates on a permission inside
         * `authorize()` rather than on the route), and it is the one 403 in the
         * application a client cannot recognise. `PermissionUnauthorizedException`
         * from the route middleware was never affected, which is why the gap
         * only shows on the routes whose permission lives in the request.
         *
         * Both classes are registered: the Symfony one is what actually
         * arrives, and the Laravel one stays for any path that reaches the
         * callbacks before `prepareException` has run.
         */
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('forbidden', 'غير مصرح لك بهذا الإجراء', 403);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
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

        /*
         * Batch 67 — the three answers that still left the envelope. A route-level
         * `throttle:` refusal (the named limiters answer in their own words already)
         * rendered Laravel's bare `{"message": "Too Many Attempts."}`; a wrong verb and
         * any other HTTP exception did the same; and an unexpected exception answered
         * `{"message": "Server Error"}` — or, with `APP_DEBUG` on, the file, the line,
         * the SQL and a stack trace. With `APP_DEBUG` off, nothing about the failure
         * leaves the server but its status.
         */
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    'rate_limited',
                    'محاولات كثيرة جدًا، حاول لاحقًا',
                    429,
                    meta: ['retry_after' => (int) ($e->getHeaders()['Retry-After'] ?? 60)],
                )->withHeaders($e->getHeaders());
            }
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('method_not_allowed', 'الطريقة غير مسموح بها لهذا المسار', 405)
                    ->withHeaders($e->getHeaders());
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                $status = $e->getStatusCode();

                return ApiResponse::error(
                    match (true) {
                        $status === 413 => 'payload_too_large',
                        $status === 503 => 'maintenance',
                        $status >= 500 => 'server_error',
                        default => 'http_error',
                    },
                    $status >= 500 ? 'حدث خطأ في الخادم، حاول لاحقًا' : 'تعذّر تنفيذ الطلب',
                    $status,
                )->withHeaders($e->getHeaders());
            }
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            // A response somebody already built — a named limiter's own 429, an
            // `abort(response())` — is passed through untouched.
            if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return null;
            }

            if ($request->is('api/*') && ! config('app.debug')) {
                return ApiResponse::error('server_error', 'حدث خطأ في الخادم، حاول لاحقًا', 500);
            }
        });
    })->create();
