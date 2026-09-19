<?php

namespace App\Support\Auth;

use App\Enums\ErrandStatus;
use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Models\ErrandRequest;
use App\Models\Order;
use App\Models\ScheduledOrder;
use App\Models\ServiceZone;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Batch 72 — what «delete my account» really erases, for a **customer**.
 *
 * Apple (5.1.1(v)) and Google Play require that deleting an account deletes the personal
 * data behind it, not just the sign-in. Before this class the action anonymised the
 * `users` row and left everything else — saved addresses, the exact pin and street on every
 * order, complaint photos, notification history — attached to an id nobody could see a name
 * for but that anyone with the database could read.
 *
 * | | |
 * |---|---|
 * | **Erased** (hard delete) | saved addresses (soft-deleted ones too), favourites, the basket, notifications, scheduled orders that are over, sign-in sessions and tokens, linked social accounts, verified-phone rows, pending password-reset and OTP rows for the old phone, complaint photos, the profile photo |
 * | **Blanked** on records that stay | on every order, order group and errand of the customer: name → «مستخدم محذوف», contact phone, notes and instructions removed, the street address replaced by the area, the pin coarsened to ~1 km; review comments removed |
 * | **Kept** | the orders, payments, refunds, wallet/settlement rows and the platform fee they carry (accounting, tax, disputes); complaint and rating rows (their text stays, their photos go); promo-usage rows; SMS send counters (auto-pruned at 30 days); audit logs |
 *
 * Nothing that another party's record points at is removed: the restaurant and the captain
 * still see the order, with the anonymised name and the area, and every foreign key holds.
 *
 * The caller wraps {@see self::erase()} in a transaction and deletes the returned files
 * **after** the commit — a rolled-back erasure must not have already deleted a photo.
 */
final class AccountErasure
{
    public const DELETED_NAME = 'مستخدم محذوف';

    public const DELETED_ADDRESS = 'عنوان محذوف';

    /**
     * What stops a deletion: an order that is not over, an errand that is not over, a
     * scheduled order that has not been placed yet.
     *
     * @return array{active_orders: int, active_errands: int, scheduled_orders: int}
     */
    public static function activeWork(User $user): array
    {
        return [
            'active_orders' => Order::query()->where('user_id', $user->id)
                ->whereNotIn('status', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value])->count(),
            'active_errands' => ErrandRequest::query()->where('user_id', $user->id)
                ->whereIn('status', array_map(fn (ErrandStatus $s) => $s->value, ErrandStatus::active()))->count(),
            'scheduled_orders' => ScheduledOrder::query()->where('user_id', $user->id)
                ->where('status', ScheduledOrder::STATUS_SCHEDULED)->count(),
        ];
    }

    /** @throws ApiException `409 active_order_blocks_deletion` */
    public static function assertNoActiveWork(User $user): void
    {
        $work = self::activeWork($user);

        if (array_sum($work) > 0) {
            throw new ApiException(
                'active_order_blocks_deletion',
                'لديك طلب جارٍ. انتظر حتى يكتمل أو ألغِه قبل حذف الحساب.',
                409,
                meta: $work,
            );
        }
    }

    /**
     * Erase the customer's personal data. Runs inside the caller's transaction; the user row
     * itself is the caller's to anonymise last.
     *
     * @return list<string> the URLs of the files to delete once the transaction has committed
     */
    public static function erase(User $user): array
    {
        $id = $user->id;
        $files = self::files($user);

        // The default-address pointer first: `users.default_address_id` references the rows below.
        DB::table('users')->where('id', $id)->update(['default_address_id' => null]);

        // Raw table deletes on purpose: `user_addresses` and `carts` soft-delete, and a
        // soft-deleted address still holds the street and the pin.
        foreach (['user_addresses', 'favorites', 'carts', 'notifications', 'social_accounts', 'user_verified_phones', 'sessions'] as $table) {
            DB::table($table)->where('user_id', $id)->delete();
        }

        // A basket's items and their options, and a notification's delivery rows, go by cascade.
        DB::table('scheduled_orders')->where('user_id', $id)->where('status', '!=', ScheduledOrder::STATUS_SCHEDULED)->delete();

        // What was asked of this phone and this email while the account lived.
        if ($user->phone !== null && $user->phone !== '') {
            DB::table('otp_codes')->where('phone', $user->phone)->delete();
        }

        if ($user->email !== null && $user->email !== '') {
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        }

        // Complaint photos: the rows and the words stay (a dispute is a record), the pictures go.
        DB::table('complaints')->where('user_id', $id)->update(['attachments' => null]);
        DB::table('complaint_messages')->where('sender_id', $id)->update(['attachments' => null]);

        DB::table('ratings')->where('user_id', $id)->update(['comment' => null]);

        self::blankOrders($id);
        self::blankErrands($id);

        $user->deviceTokens()->delete();
        Sessions::revokeAll($user);

        return $files;
    }

    /** @return list<string> */
    private static function files(User $user): array
    {
        $urls = [];

        if (is_string($user->avatar) && $user->avatar !== '') {
            $urls[] = $user->avatar;
        }

        foreach ([
            DB::table('complaints')->where('user_id', $user->id)->pluck('attachments'),
            DB::table('complaint_messages')->where('sender_id', $user->id)->pluck('attachments'),
        ] as $column) {
            foreach ($column as $raw) {
                foreach ((array) json_decode((string) $raw, true) as $url) {
                    if (is_string($url) && $url !== '') {
                        $urls[] = $url;
                    }
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * The customer's orders keep their money and their state and lose the person.
     *
     * The street address becomes the area — the snapshot's own `area`, else the zone's name —
     * so a captain's history and a restaurant's list still say roughly where it went.
     */
    private static function blankOrders(int $userId): void
    {
        $zones = ServiceZone::query()->pluck('name_ar', 'id');

        DB::table('orders')->where('user_id', $userId)->orderBy('id')->select(['id', 'service_zone_id', 'delivery_address_snapshot'])
            ->chunkById(200, function ($orders) use ($zones): void {
                foreach ($orders as $order) {
                    $snapshot = json_decode((string) $order->delivery_address_snapshot, true);
                    $area = is_array($snapshot) && filled($snapshot['area'] ?? null) ? $snapshot['area'] : ($zones[$order->service_zone_id] ?? null);
                    $city = is_array($snapshot) && filled($snapshot['city'] ?? null) ? $snapshot['city'] : null;

                    DB::table('orders')->where('id', $order->id)->update([
                        'customer_name_snapshot' => self::DELETED_NAME,
                        'delivery_contact_phone' => null,
                        'delivery_address_text' => $area ?? self::DELETED_ADDRESS,
                        'delivery_address_snapshot' => $area === null && $city === null ? null : json_encode(array_filter(['area' => $area, 'city' => $city]), JSON_UNESCAPED_UNICODE),
                        'delivery_latitude' => DB::raw('ROUND(delivery_latitude, 2)'),
                        'delivery_longitude' => DB::raw('ROUND(delivery_longitude, 2)'),
                        'customer_note' => null,
                        'delivery_instructions' => null,
                    ]);
                }
            });

        DB::table('order_groups')->where('user_id', $userId)->orderBy('id')->select(['id', 'service_zone_id'])
            ->chunkById(200, function ($groups) use ($zones): void {
                foreach ($groups as $group) {
                    DB::table('order_groups')->where('id', $group->id)->update([
                        'delivery_contact_phone' => null,
                        'delivery_address_text' => $zones[$group->service_zone_id] ?? self::DELETED_ADDRESS,
                        'delivery_latitude' => DB::raw('ROUND(delivery_latitude, 2)'),
                        'delivery_longitude' => DB::raw('ROUND(delivery_longitude, 2)'),
                        'customer_note' => null,
                        'delivery_instructions' => null,
                        'address_id' => null,
                    ]);
                }
            });
    }

    /** An errand keeps its fee, its margin and its state; the two addresses and the story go. */
    private static function blankErrands(int $userId): void
    {
        DB::table('errand_requests')->where('user_id', $userId)->update([
            'pickup_address_text' => self::DELETED_ADDRESS,
            'dropoff_address_text' => self::DELETED_ADDRESS,
            'pickup_contact_phone' => null,
            'dropoff_contact_phone' => null,
            'pickup_lat' => DB::raw('ROUND(pickup_lat, 2)'),
            'pickup_lng' => DB::raw('ROUND(pickup_lng, 2)'),
            'dropoff_lat' => DB::raw('ROUND(dropoff_lat, 2)'),
            'dropoff_lng' => DB::raw('ROUND(dropoff_lng, 2)'),
            'details' => '',
            'purchases_note' => null,
            'cancel_reason' => null,
        ]);
    }
}
