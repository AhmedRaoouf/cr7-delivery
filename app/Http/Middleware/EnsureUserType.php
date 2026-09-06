<?php

namespace App\Http\Middleware;

use App\Enums\UserType;
use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * doc 12 §1.1 — `users.type` is the only authorization truth. `X-App` (doc 12 §1.1,
 * enforced by EnsureClientMatchesUserType) tells us which binary is calling; this
 * middleware narrows a shared binary (customer + driver both send `X-App: app`)
 * down to the specific type a route requires, e.g. `type:customer`.
 *
 * More than one type may be named — `type:customer,driver`. The complaints box is why:
 * `DRV-32` sends a captain to the same «تذاكري» screen the customer uses, and the routes
 * behind it were `type:customer`, so every captain who opened their own tickets got a 403
 * from a screen the app offers them.
 */
class EnsureUserType
{
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $allowed = array_map(UserType::from(...), $types);

        if (! in_array($request->user()?->type, $allowed, true)) {
            throw new ApiException('wrong_client', 'هذا الحساب لا يمكنه الدخول من هذه الواجهة', 403);
        }

        return $next($request);
    }
}
