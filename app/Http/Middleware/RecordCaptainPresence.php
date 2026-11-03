<?php

namespace App\Http\Middleware;

use App\Models\Driver;
use App\Support\DriverPresence;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * doc 99 §2 — D-170. Every authenticated captain request is a heartbeat.
 *
 * The 1.0.23 app polls `GET /driver/offers` every few seconds while its switch is on, so
 * this is how attendance is recorded without an app update. At most one write per
 * captain per {@see DriverPresence::TOUCH_THROTTLE_SECONDS}, decided by an atomic
 * `Cache::add` before the captain's row is even read. `/driver/status` and
 * `/driver/location` touch the session themselves, unthrottled, so they are skipped here.
 * A failure is logged and never fails the request.
 */
class RecordCaptainPresence
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $request->is('api/v1/driver/status', 'api/v1/driver/location')) {
            DriverPresence::heartbeat(
                (int) $user->id,
                fn () => Driver::query()->where('user_id', $user->id)->first(),
            );
        }

        return $next($request);
    }
}
