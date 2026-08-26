<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** doc 08 §2 — GET /config: app bootstrap payload. */
class ConfigController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = Setting::query()->pluck('value', 'key');

        return ApiResponse::success([
            'force_update' => [
                'android_min' => $settings->get('app.force_update.android_min_version', '1.0.0'),
                'ios_min' => $settings->get('app.force_update.ios_min_version', '1.0.0'),
                'store_url_android' => null,
                'store_url_ios' => null,
            ],
            'maintenance' => [
                'enabled' => (bool) $settings->get('app.maintenance_mode', false),
                'message' => null,
            ],
            'support' => [
                'phone' => $settings->get('support.phone'),
                'whatsapp' => $settings->get('support.whatsapp'),
                'email' => $settings->get('support.email'),
            ],
            'payment' => [
                'vodafone_cash_number' => $settings->get('payment.vodafone_cash_number'),
                'instapay_handle' => $settings->get('payment.instapay_handle'),
                'transfer_deadline_minutes' => $settings->get('payment.transfer_deadline_minutes', 15),
                'reminder_before_minutes' => $settings->get('payment.reminder_before_minutes', 5),
            ],
            'currency' => [
                'code' => 'EGP',
                'symbol_ar' => 'ج.م',
                'symbol_en' => 'EGP',
                'decimals' => 2,
            ],
            'cart' => [
                'max_restaurants' => $settings->get('cart.max_restaurants', 3),
                'max_qty_per_line' => $settings->get('cart.max_qty_per_line', 10),
            ],
            'order_statuses' => collect(\App\Enums\OrderStatus::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'color' => $s->color(),
            ]),
            'cancel_reasons' => [],
        ]);
    }
}
