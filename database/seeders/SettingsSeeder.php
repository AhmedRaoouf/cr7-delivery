<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/** doc 03 §9 — every runtime knob, seeded to its documented default. */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            'dispatch.batch_size' => [3, 'dispatch'],
            'dispatch.radius_km' => [5, 'dispatch'],
            'dispatch.radius_expand_km' => [2, 'dispatch'],
            // **Read by nothing since 2026-09-08.** An offer no longer expires — the
            // operator ruled it stays until the captain answers — and the clock that
            // used to end a round now lives on the order as
            // `dispatch.rebroadcast_interval_seconds`. Left seeded so an operator who
            // had tuned it does not meet a missing row, and so old
            // `order_driver_attempts` rows still read against a number that explains
            // them.
            'dispatch.offer_ttl_seconds' => [30, 'dispatch'],
            // Every minute, offer the order to captains who have not been asked yet.
            // The ones already holding it keep it.
            'dispatch.rebroadcast_interval_seconds' => [60, 'dispatch'],
            // …and after five minutes of that, tell the operators once.
            'dispatch.escalate_after_seconds' => [300, 'dispatch'],
            'dispatch.max_rounds' => [3, 'dispatch'],
            'dispatch.allow_neighbour_zones' => [true, 'dispatch'],
            'dispatch.mode_multi_restaurant' => ['driver_per_restaurant', 'dispatch'],
            'dispatch.multi_pickup_ready_window_seconds' => [600, 'dispatch'],
            'dispatch.location_stale_seconds' => [90, 'dispatch'],
            // doc 04 E-71 — how long a stranded order waits before `cr7:tick` offers it
            // to the nearest captains again. Long enough not to re-ring the same three
            // phones every pass, short enough that a captain coming online is used.
            'dispatch.retry_interval_seconds' => [90, 'dispatch'],
            // The last round drops the circle and the batch cap: whoever is free
            // hears about it, ranked nearest-first as always. Turn it off and an
            // order can sit unreachable while a captain one kilometre past the
            // ladder waits with an empty screen.
            'dispatch.last_call_all_drivers' => [1, 'dispatch'],
            // Every online, free captain hears about every order, from round 1.
            // Turn it off to get the nearest-3 ladder back — but only on a host
            // where `* * * * * php artisan schedule:run` is actually installed,
            // because without that clock the ladder never climbs past round 1.
            'dispatch.offer_to_all_online' => [1, 'dispatch'],

            'driver.location_stale_seconds' => [60, 'driver'],
            'driver.unresponsive_seconds' => [300, 'driver'],
            'driver.default_earning_type' => ['fixed', 'driver'],
            'driver.default_earning_value' => [15.00, 'driver'],
            'driver.extra_pickup_earning' => [8.00, 'driver'],

            'restaurant.accept_timeout_minutes' => [5, 'restaurant'],
            'restaurant.prep_minutes_min' => [5, 'restaurant'],
            'restaurant.prep_minutes_max' => [120, 'restaurant'],
            'restaurant.prep_extension_max_minutes' => [15, 'restaurant'],
            'restaurant.prep_timer_tick_seconds' => [15, 'restaurant'],
            'restaurant.prep_warn_at_percent' => [25, 'restaurant'],

            'payment.transfer_deadline_minutes' => [15, 'payment'],
            'payment.reminder_before_minutes' => [5, 'payment'],
            'payment.max_unpaid_before_cash_only' => [3, 'payment'],
            'payment.strike_window_days' => [30, 'payment'],
            'payment.cash_only_days' => [30, 'payment'],
            'payment.vodafone_cash_number' => ['01000000000', 'payment'],
            'payment.instapay_handle' => ['cr7delivery@instapay', 'payment'],

            'order.customer_cancel_until_status' => ['confirmed', 'order'],
            'order.customer_confirm_timeout_minutes' => [10, 'order'],
            'order.confirm_reminder_before_minutes' => [3, 'order'],
            'order.max_unconfirmed_before_cash_only' => [3, 'order'],
            'order.delivery_otp_length' => [4, 'order'],
            'order.delivery_otp_resend_max' => [3, 'order'],
            'order.otp_max_attempts' => [5, 'order'],
            'order.min_value_default' => [50.00, 'order'],
            'order.renotify_limit' => [1, 'order'],

            'cart.max_restaurants' => [3, 'cart'],
            'cart.max_qty_per_line' => [10, 'cart'],

            'delivery.extra_stop_fee' => [10.00, 'delivery'],
            'delivery.multi_pickup_max_spread_km' => [2.5, 'delivery'],

            // doc 40 — «اطلب كابتن». The fee is the customer's offer, so these three only
            // shape the *suggestion* the request screen starts them from. Base + per-km
            // against the straight-line distance, floored at the minimum.
            'errands.base_fee' => [20.00, 'errands'],
            'errands.per_km_fee' => [5.00, 'errands'],
            'errands.min_fee' => [20.00, 'errands'],
            // How long an errand may sit unclaimed before the operators are told.
            'errands.escalate_after_minutes' => [5, 'errands'],
            // The errand half of the same rule: a captain who came online after the
            // request was published had never been told it existed.
            'errands.republish_interval_minutes' => [1, 'errands'],

            'commission.default_rate' => [10.00, 'commission'],

            // D-63..D-65 — the platform service fee («رسوم الخدمة»). A fixed amount
            // charged once per order group, on top of the food and the delivery fee,
            // and kept by the platform.
            'platform.order_fee' => [5.00, 'platform'],
            // D-64 — a direct order is a delivery the platform sold, so it carries the
            // fee too. Flip this to false to exempt phone orders without a deploy.
            'platform.order_fee_applies_to_direct' => [true, 'platform'],
            // D-65 — refunded when the platform failed the order, never when the
            // customer changed their mind after confirming.
            'platform.order_fee_refundable_on_platform_failure' => [true, 'platform'],
            'best_sellers.window_days' => [30, 'best_sellers'],
            'best_sellers.count' => [5, 'best_sellers'],

            'direct_orders.enabled' => [true, 'direct_orders'],
            'direct_orders.daily_cap_per_restaurant' => [20, 'direct_orders'],
            'direct_orders.ratio_alert_threshold' => [0.40, 'direct_orders'],
            'direct_orders.sms_customer_on_dispatch' => [false, 'direct_orders'],
            'direct_orders.restaurant_may_set_fee' => [false, 'direct_orders'],

            // APP-04 prints this window back to the applicant.
            'driver_applications.review_hours' => [48, 'driver_applications'],

            'admin.require_2fa' => [['super_admin', 'finance'], 'admin'],
            'admin.invite_ttl_hours' => [48, 'admin'],
            'admin.session_idle_minutes' => [60, 'admin'],

            'fleet.broadcast_interval_seconds' => [5, 'fleet'],
            'fleet.trail_minutes' => [30, 'fleet'],

            'support.phone' => ['01000000000', 'support'],
            'support.whatsapp' => ['01000000000', 'support'],
            'support.email' => ['support@cr7delivery.com', 'support'],
            'support.first_response_minutes' => [60, 'support'],
            'support.resolution_hours.high' => [4, 'support'],
            'support.resolution_hours.normal' => [24, 'support'],
            'support.resolution_hours.low' => [72, 'support'],
            'support.restaurant_ticket_daily_cap' => [10, 'support'],
            'support.mirror_late_delivery' => [false, 'support'],

            'app.force_update.android_min_version' => ['1.0.0', 'app'],
            'app.force_update.ios_min_version' => ['1.0.0', 'app'],
            'app.maintenance_mode' => [false, 'app'],
        ];

        foreach ($settings as $key => [$value, $group]) {
            Setting::query()->firstOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
        }
    }
}
