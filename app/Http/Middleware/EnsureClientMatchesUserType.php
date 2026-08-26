<?php

namespace App\Http\Middleware;

use App\Enums\UserType;
use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * doc 12 §1.1 / FR-ROLE-04: `X-App` says which binary is calling, `users.type`
 * is the only authorization truth. A driver signing in from the customer path
 * still lands in the captain shell — both share `X-App: app`.
 */
class EnsureClientMatchesUserType
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $app = $request->header('X-App');

            $allowed = match ($app) {
                'app' => in_array($user->type, [UserType::Customer, UserType::Driver], true),
                'restaurant' => $user->type === UserType::Restaurant,
                'admin' => $user->type === UserType::Admin,
                default => false,
            };

            if (! $allowed) {
                throw new ApiException('wrong_client', 'هذا الحساب لا يمكنه الدخول من هذه الواجهة', 403);
            }
        }

        return $next($request);
    }
}
