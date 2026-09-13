<?php

namespace App\Http\Controllers\Api\Shared;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\ApiResponse;
use App\Support\FirstOrderPerk;
use App\Support\MapStyles;
use App\Support\Otp;
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
                // The flyer prints two numbers, so the app renders two rows. A
                // missing or blank alt is dropped by the client, not shown as a
                // second row that dials nothing.
                'phone_alt' => $settings->get('support.phone_alt'),
                'whatsapp' => $settings->get('support.whatsapp'),
                'email' => $settings->get('support.email'),
                'hours_note' => $settings->get('support.hours_note'),
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
            // D-63 — the app shows «رسوم الخدمة» on the summary before it has a
            // quote. The number charged is still the quote's, never this one.
            'platform' => [
                'order_fee' => (float) $settings->get('platform.order_fee', 0),
                'order_fee_label_ar' => 'رسوم الخدمة',
                'order_fee_label_en' => 'Service fee',
            ],
            // «خلي اول اورد للحساب توصيل مجاني» — operator, 2026-09-08. The basket
            // draws its delivery line before it has a quote, so it has to know here
            // or it would print a fee the checkout then waives. `eligible` needs the
            // account, and this route is public: it is resolved from the token when
            // there is one and is false for a visitor, which is the honest answer —
            // the platform does not yet know whether they are new.
            'first_order' => [
                'free_delivery_enabled' => FirstOrderPerk::isEnabled(),
                'free_delivery_eligible' => FirstOrderPerk::appliesTo(auth('sanctum')->user()),
            ],
            // The tile template, its zoom scale and the attribution the
            // provider's terms require. Published here rather than compiled
            // into the app so rotating the token is a server edit, not a store
            // release.
            'maps' => MapStyles::payload(),
            // Only ever non-null while `otp.mode = test` on ADM-46, and then it
            // is already public. The app prints it as «كود التجربة» so nobody has
            // to go read the server log to sign in.
            'auth' => [
                'static_otp' => Otp::publishedTestCode(),
            ],
            // Realtime. The Pusher *key* is a public identifier — it is meant to sit
            // in every client — while the secret never leaves the server. Published
            // here, like the map token, so rotating the Pusher app is a server edit
            // rather than a store release. `enabled` false means every client keeps
            // its polling fallback and opens no socket at all.
            'realtime' => [
                'driver' => config('broadcasting.default') === 'pusher' ? 'pusher' : null,
                'enabled' => config('broadcasting.default') === 'pusher' && filled(config('broadcasting.connections.pusher.key')),
                'key' => config('broadcasting.default') === 'pusher' ? config('broadcasting.connections.pusher.key') : null,
                'cluster' => config('broadcasting.connections.pusher.options.cluster'),
                'auth_endpoint' => url('/api/v1/broadcasting/auth'),
            ],
            'cart' => [
                'max_restaurants' => $settings->get('cart.max_restaurants', 3),
                'max_qty_per_line' => $settings->get('cart.max_qty_per_line', 10),
            ],
            'order_statuses' => collect(OrderStatus::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'color' => $s->color(),
            ]),
            'cancel_reasons' => [],
        ]);
    }
}
