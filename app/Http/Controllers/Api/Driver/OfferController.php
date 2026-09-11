<?php

namespace App\Http\Controllers\Api\Driver;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\DriverEarningCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §5 — GET /driver/offers, GET /driver/offers/{orderId}: open offers, recovery after a missed push. */
class OfferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();

        // An offer with no `expires_at` is open until it is answered (2026-09-08).
        // The old `expires_at > now()` filter hid every one of them, so the recovery
        // list a captain falls back to after a missed push would have come back empty
        // for exactly the offers that are still theirs to take.
        // A captain already on a trip has no offers, whatever rows are lying
        // about. Without this the recovery poll could draw an offer whose
        // «قبول» answers `already_on_a_trip` — and the client shows
        // `offers.first`, so one stale row is enough to wedge the screen.
        if ($driver->active_order_id !== null) {
            return ApiResponse::success([]);
        }

        $offers = $driver->driverAttempts()
            ->where('result', 'sent')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            // An order that has since found a captain, been cancelled or been
            // delivered is not an offer any more. The row said `sent` forever
            // because only the accepting path ever superseded its siblings.
            ->whereHas('order', fn ($q) => $q
                ->whereNull('driver_id')
                ->whereIn('status', [OrderStatus::SearchingDriver, OrderStatus::WaitingForDriver]))
            ->with(['order.restaurant', 'assignedBy:id,name'])
            ->get()
            ->map(fn ($attempt) => $this->formatOffer($attempt));

        return ApiResponse::success($offers);
    }

    public function show(Request $request, int $orderId): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();

        $attempt = $driver->driverAttempts()
            ->where('order_id', $orderId)
            ->where('result', 'sent')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with(['order.restaurant', 'assignedBy:id,name'])
            ->firstOrFail();

        return ApiResponse::success($this->formatOffer($attempt));
    }

    private function formatOffer($attempt): array
    {
        $order = $attempt->order;
        $driver = $attempt->driver;

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'restaurant_order_ref' => $order->restaurant_order_ref,
            'restaurant' => [
                'name' => $order->restaurant->name_ar,
                'address' => $order->restaurant->address,
                'latitude' => (float) $order->restaurant->latitude,
                'longitude' => (float) $order->restaurant->longitude,
                // Null when the captain has never reported a position — they are
                // offered the order anyway. `0.0` here would claim the restaurant
                // is at their feet.
                'distance_km' => $attempt->distance_km === null ? null : (float) $attempt->distance_km,
            ],
            'customer_area' => $order->delivery_address_text,
            'delivery_distance_km' => (float) $order->distance_km,
            'items_count' => $order->items()->sum('quantity'),
            'order_total' => (float) $order->total,
            'payment_method' => $order->payment_method,
            'cash_to_collect' => $order->cashToCollect(),
            'your_earning' => DriverEarningCalculator::calculate($order, $driver),
            'round' => $attempt->round,
            // Both null on every offer written since 2026-09-08. The app reads that as
            // «no countdown» and shows the offer until it is answered.
            'expires_at' => $attempt->expires_at,
            'seconds_left' => $attempt->expires_at === null
                ? null
                : max(0, now()->diffInSeconds($attempt->expires_at, false)),
            // An assignment is an offer with a name on it — `DRV-13` says who sent it,
            // so the captain knows this one came from the office rather than the queue.
            'assigned_by_admin' => $attempt->assigned_by !== null,
            'assigned_by_name' => $attempt->assignedBy?->name,
        ];
    }
}
