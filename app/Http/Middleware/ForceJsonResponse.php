<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batch 67 — every `/api/*` answer is JSON, whatever the caller sent in `Accept`.
 *
 * Measured on production 2026-09-18: `GET /api/v1/admin/users` **without**
 * `Accept: application/json` answered **500** with Laravel's HTML «Server Error»
 * page; with the header it answered the 401 envelope. The difference is Laravel's
 * `Authenticate` middleware: for a request that does not "expect JSON" it tries to
 * redirect the guest to `route('login')`, this API has no such route, and the
 * `RouteNotFoundException` that follows is the 500. Every scanner on the internet
 * sends no `Accept` header, so every one of them was being told «server error»
 * rather than «sign in».
 *
 * Setting the header here — prepended to the `api` group, before `auth:sanctum` — makes
 * `expectsJson()` true for every API request, so the guest path is a 401 and every
 * validation, 403 and 404 renders through `bootstrap/app.php`'s envelope.
 *
 * The same middleware clamps `per_page`. Every list endpoint reads it with
 * `$request->integer('per_page', 20)` and passes it to `paginate()` unbounded, so
 * `?per_page=1000000` loaded a whole table in one request. Clamping it once here
 * covers every list, including the ones written after this.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        if ($request->query->has('per_page') || $request->request->has('per_page')) {
            $max = max(1, (int) config('security.max_per_page', 200));
            $raw = $request->input('per_page');
            $value = is_numeric($raw) ? (int) $raw : 20;
            $clamped = min(max($value, 1), $max);

            // `merge()` writes to the input bag every reader uses (`input()`,
            // `integer()`, `query()` on a GET), so the controllers need no change.
            $request->merge(['per_page' => $clamped]);

            if ($request->query->has('per_page')) {
                $request->query->set('per_page', $clamped);
            }
        }

        return $next($request);
    }
}
