<?php

namespace App\Http\Middleware;

use App\Support\AdminScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batch 67 — doc 12 §10.1's last row: **`settings`, `roles`, `users`, `audit_logs` are
 * `global` only, whatever the role grants.**
 *
 * Nothing enforced that. A permission is a string on a role, a role can be cloned
 * (`ADM-50`), and a clone of `super_admin` handed to a city-scoped operator carried
 * `users.manage`, `roles.manage`, `settings.manage` and `scope.manage` into a scope
 * that was never meant to reach them — including `POST /admin/users/{self}/scope`,
 * which let that operator make themselves global.
 *
 * A scoped admin reaching one of these routes is answered **404**, the same answer an
 * out-of-scope row gets (D-51: existence is not leaked, and `out_of_scope` is never a
 * code on the wire). It runs after the `permission:` middleware, so an admin without
 * the permission still hears the 403 they always did.
 *
 * The broadcast push is here too: `audience = all` reaches every account on the
 * platform, which no city-scoped operator may address.
 */
class EnsureGlobalAdminScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user !== null && $user->isAdmin() && AdminScope::isGlobal($user), 404);

        return $next($request);
    }
}
