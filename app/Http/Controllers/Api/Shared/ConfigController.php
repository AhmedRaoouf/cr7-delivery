<?php

namespace App\Http\Controllers\Api\Shared;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\AddressCompleteness;
use App\Support\AddressContactPhone;
use App\Support\ApiResponse;
use App\Support\BasketLimit;
use App\Support\FirstOrderPerk;
use App\Support\MapStyles;
use App\Support\Otp;
use App\Support\PaymentMethods;
use App\Support\SettingsCatalog;
use Illuminate\Http\JsonResponse;

/**
 * doc 08 §2 — GET /config: app bootstrap payload.
 *
 * Not cached on the server — every read is live, so there is no cache key here to get
 * wrong. It does answer differently per request: `maps` follows `Accept-Language` (an
 * Arabic basemap, when one is configured) and `first_order.free_delivery_eligible`
 * follows the bearer token. `Vary` says both, so a proxy or a client-side cache that
 * honours HTTP keys on them rather than handing one person's answer to another.
 */
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
            // D-109 — `methods` is what the picker draws: the switched-on methods
            // only, each with its own destination. The flat keys stay for the
            // installs that read them.
            'payment' => [
                'methods' => PaymentMethods::enabled(),
                'cash_enabled' => SettingsCatalog::bool('payment.cash_enabled'),
                'vodafone_cash_enabled' => SettingsCatalog::bool('payment.vodafone_cash_enabled'),
                'instapay_enabled' => SettingsCatalog::bool('payment.instapay_enabled'),
                'vodafone_cash_number' => SettingsCatalog::get('payment.vodafone_cash_number'),
                'vodafone_cash_number_alt' => SettingsCatalog::get('payment.vodafone_cash_number_alt'),
                'instapay_handle' => SettingsCatalog::get('payment.instapay_handle'),
                'transfer_deadline_minutes' => SettingsCatalog::int('payment.transfer_deadline_minutes'),
                'reminder_before_minutes' => SettingsCatalog::int('payment.reminder_before_minutes'),
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
            // release. Built for the request's locale — `maps.language` says which.
            'maps' => MapStyles::payload(),
            // D-106 / D-107. `static_otp` is only ever non-null while `otp.mode =
            // test` on ADM-46, and then it is already public — the app prints it as
            // «كود التجربة». It never applies to an admin's reset or to a password
            // change, which are real SMS whatever the mode; the per-send `static_otp`
            // is the one to prefill from. `register_requires_otp` false means the
            // sign-up form posts phone + name + password with no code step.
            'auth' => [
                'static_otp' => Otp::publishedTestCode(),
                'otp_test_mode' => Otp::testMode(),
                'register_requires_otp' => SettingsCatalog::bool('auth.register_requires_otp'),
                'admin_login_by_email' => true,
            ],
            // D-111 / D-112 — what an address must carry before it can be ordered to
            // (the pin and the area always, so never listed), and what the phone
            // field allows.
            'addresses' => [
                'required_fields' => array_map(static fn (string $field) => [
                    'field' => $field,
                    'label' => AddressCompleteness::LABELS_AR[$field],
                    'label_en' => AddressCompleteness::LABELS_EN[$field],
                ], AddressCompleteness::requiredFields()),
                'alternate_phone_allowed' => AddressContactPhone::allowed(),
                'alternate_phone_requires_otp' => AddressContactPhone::requiresOtp(),
            ],
            // D-100 — the web board rings the same schedule the phone does.
            'ring' => [
                'enabled' => SettingsCatalog::bool('ring.enabled'),
                'duration_seconds' => SettingsCatalog::int('ring.duration_seconds'),
                'gap_seconds' => SettingsCatalog::int('ring.gap_seconds'),
                'max_attempts' => SettingsCatalog::int('ring.max_attempts'),
            ],
            // D-108 — off, the captain confirms pickup / delivery with one button.
            // The switch is snapshotted onto each order; an order's own flag wins.
            'orders' => [
                'pickup_code_required' => SettingsCatalog::bool('orders.pickup_code_required'),
                'delivery_code_required' => SettingsCatalog::bool('orders.delivery_code_required'),
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
            // D-114 — neither client hard-codes the limit.
            'cart' => [
                'max_restaurants' => BasketLimit::maxRestaurants(),
                'max_qty_per_line' => BasketLimit::maxQuantityPerLine(),
            ],
            'order_statuses' => collect(OrderStatus::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'color' => $s->color(),
            ]),
            'cancel_reasons' => [],
        ])->setVary(['Accept-Language', 'Authorization'], false);
    }
}
