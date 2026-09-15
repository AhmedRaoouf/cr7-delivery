<?php

namespace App\Http\Controllers\Api\Driver;

use App\Events\DriverLocationChanged;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Support\ApiResponse;
use App\Support\DispatchService;
use App\Support\DriverTrip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** doc 08 §5 — GET /driver/me, POST /driver/status, POST /driver/location. */
class DriverMeController extends Controller
{
    /** See `broadcastLocation()` — the cadence the fleet map and the tracking screen get. */
    private const BROADCAST_EVERY_SECONDS = 10;

    public function show(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->with(['city', 'assignedZones', 'activeOrder'])->firstOrFail();

        return ApiResponse::success($driver);
    }

    public function status(Request $request, DispatchService $dispatch): JsonResponse
    {
        $validated = $request->validate(['is_online' => ['required', 'boolean']]);
        $driver = $request->user()->driver()->firstOrFail();

        if (! $driver->is_active) {
            throw new ApiException('account_suspended', 'حسابك موقوف حاليًا', 423);
        }

        // doc 63 D-117 — the captain's focus and «carries nothing» re-derived from their
        // orders. There is no busy check after it any more: it used to refuse going online
        // while `active_order_id` was set, and a leftover one — an order long since
        // delivered, or cancelled by an admin — made that refusal permanent. With batching
        // a captain on a trip going online is the point.
        DriverTrip::refresh($driver);

        // DRV-07's «متصل منذ» reads this. Set only on the transition, so a captain
        // who re-sends `is_online: true` on a reconnect does not restart their shift.
        $goingOnline = $validated['is_online'] && ! $driver->is_online;

        $driver->update([
            'is_online' => $validated['is_online'],
            'online_since' => $validated['is_online'] ? ($goingOnline ? now() : $driver->online_since) : null,
        ]);

        // «لو في طلبات ملهاش حد وفي كابتن لسه فاتح، أهو تبدأ تبعتله طلب» — the
        // captain hears about the backlog the moment they come on shift rather
        // than whenever the next sweep happens to run — only what fits their trip,
        // when they carry one.
        if ($goingOnline) {
            $dispatch->offerBacklogTo($driver);
        }

        return ApiResponse::success([
            'is_online' => $driver->is_online,
            'online_since' => $driver->online_since,
        ]);
    }

    public function location(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();

        $body = $request->all();
        $points = array_is_list($body) ? $body : [$body];

        $validated = collect($points)->map(fn ($point) => validator($point, [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'speed' => ['nullable', 'numeric'],
            'heading' => ['nullable', 'numeric'],
            'accuracy' => ['nullable', 'numeric'],
            'recorded_at' => ['nullable', 'date'],
        ])->validate());

        foreach ($validated as $point) {
            $driver->locations()->create([
                // The trip's focus (doc 63 D-117) — the order of the stop the captain is
                // making. A trip of three is one track, filed under the order it serves now.
                'order_id' => $driver->active_order_id,
                'latitude' => $point['latitude'],
                'longitude' => $point['longitude'],
                'speed' => $point['speed'] ?? null,
                'heading' => $point['heading'] ?? null,
                'accuracy' => $point['accuracy'] ?? null,
                'recorded_at' => $point['recorded_at'] ?? now(),
            ]);
        }

        $last = $validated->last();
        $driver->update([
            'current_lat' => $last['latitude'],
            'current_lng' => $last['longitude'],
            'location_updated_at' => now(),
        ]);

        $this->broadcastLocation($driver);

        // The ping carries the duty flag back because it is the one request a
        // captain's app makes continuously, and because the two can disagree
        // without either side noticing.
        //
        // `is_online` lives on the server and is written by four different
        // things: the captain's own toggle, sign-out, an admin, and an accepted
        // order. The app writes it once, at the toggle, and then believes itself
        // for the rest of the shift. So a captain whose shift was ended anywhere
        // else sits looking at «أنت متصل الآن», pings every 45 seconds, and is
        // invisible to every dispatch round — because dispatch reads this column,
        // not the screen. Found on a live captain on 2026-09-05: pinging every 13
        // seconds with `is_online = false`, and told nothing.
        return ApiResponse::success([
            'location_updated_at' => $driver->location_updated_at,
            'is_online' => (bool) $driver->is_online,
            'is_available' => (bool) $driver->is_available,
        ]);
    }

    /**
     * Tell the fleet map and the customer's tracking screen where this captain is.
     *
     * **Throttled to one broadcast per captain per `BROADCAST_EVERY_SECONDS`.** The app
     * pings every few seconds while a delivery is running; a message per ping would spend
     * the Pusher plan's daily budget on movement no human eye can resolve, and would cost
     * more than the polling it replaces. Ten seconds is under the eye's patience for a
     * marker and an order of magnitude under the fifteen-second poll it removes.
     *
     * `Cache::add` is the throttle rather than a timestamp comparison because it is
     * atomic: two pings arriving together — which is exactly what a phone that buffered
     * points offline sends — would both pass a read-then-compare.
     *
     * A broadcast failure must never fail a location ping. The captain's own screen and
     * the dispatcher both depend on this endpoint answering; a Pusher outage degrades the
     * map to its poll, which is why the poll is still there.
     */
    private function broadcastLocation(Driver $driver): void
    {
        $fresh = Cache::add('driver-location-broadcast:'.$driver->id, true, self::BROADCAST_EVERY_SECONDS);

        if (! $fresh) {
            return;
        }

        try {
            // Every basket on the trip, not only the focus's: each of those customers is
            // watching the same captain move (doc 63 D-116).
            $groups = DriverTrip::query($driver)->pluck('order_group_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

            broadcast(DriverLocationChanged::fromDriver($driver->loadMissing('activeOrder'), $groups));
        } catch (\Throwable $e) {
            Log::warning('driver location broadcast failed', ['driver' => $driver->id, 'error' => $e->getMessage()]);
        }
    }
}
