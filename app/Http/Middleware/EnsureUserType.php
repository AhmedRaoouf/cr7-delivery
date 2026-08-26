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
 */
class EnsureUserType
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $expected = UserType::from($type);

        if ($request->user()?->type !== $expected) {
            throw new ApiException('wrong_client', 'هذا الحساب لا يمكنه الدخول من هذه الواجهة', 403);
        }

        return $next($request);
    }
}
