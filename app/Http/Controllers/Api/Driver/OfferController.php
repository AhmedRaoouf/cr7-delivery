<?php

namespace App\Http\Controllers\Api\Driver;

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

        $offers = $driver->driverAttempts()
            ->where('result', 'sent')
            ->where('expires_at', '>', now())
            ->with('order.restaurant')
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
            ->with('order.restaurant')
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
            'cash_to_collect' => $order->payment_method->value === 'cash' ? (float) $order->total : 0,
            'your_earning' => DriverEarningCalculator::calculate($order, $driver),
            'round' => $attempt->round,
            'expires_at' => $attempt->expires_at,
            'seconds_left' => max(0, now()->diffInSeconds($attempt->expires_at, false)),
        ];
    }
}
