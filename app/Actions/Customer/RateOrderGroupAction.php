<?php

namespace App\Actions\Customer;

use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Models\OrderGroup;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * doc 08 §4 — POST /orders/{id}/ratings.
 *
 * **One rating per basket, and it closes.** D-80, operator 2026-09-11: «لما أقيّم مرة
 * خلاص ما أقيّمش تاني لما أخش على نفس الطلب — تبقى مرة واحدة بس، تقييم الـ Delivery
 * والمكان». It was `updateOrCreate` with no guard in front of it, so every reopening of a
 * delivered order offered the stars again and silently overwrote what the customer said
 * the first time; a restaurant's average could be walked up or down by one person with
 * one order and a free afternoon.
 *
 * An empty body is refused too. The app's «تخطّي» on every step sent
 * `restaurants: []` with no driver, which used to answer «تم إرسال التقييم بنجاح» having
 * written nothing at all — and then the receipt still offered the button, because nothing
 * had been rated.
 */
class RateOrderGroupAction
{
    public function execute(User $user, OrderGroup $group, array $data): void
    {
        abort_unless($group->user_id === $user->id, 404);

        $restaurantEntries = array_values(array_filter(
            $data['restaurants'] ?? [],
            fn ($entry) => ! empty($entry['restaurant_id']) && ! empty($entry['stars']),
        ));

        // doc 99 §6 — D-174: `drivers[]` beside the old single `driver`. One entry per
        // captain; when both name the same captain, `drivers[]` is the newer client's word.
        $driverEntries = [];

        foreach ([$data['driver'] ?? null, ...array_values($data['drivers'] ?? [])] as $entry) {
            if (is_array($entry) && ! empty($entry['driver_id']) && ! empty($entry['stars'])) {
                $driverEntries[(int) $entry['driver_id']] = $entry;
            }
        }

        if ($restaurantEntries === [] && $driverEntries === []) {
            throw new ApiException('rating_required', 'اختر تقييمًا واحدًا على الأقل قبل الإرسال', 422);
        }

        if (self::isRated($group)) {
            throw new ApiException('already_rated', 'تم تقييم هذا الطلب من قبل', 409);
        }

        DB::transaction(function () use ($user, $group, $restaurantEntries, $driverEntries) {
            foreach ($restaurantEntries as $entry) {
                $order = $group->orders()->where('restaurant_id', $entry['restaurant_id'])->first();

                if (! $order || $order->status !== OrderStatus::Delivered) {
                    throw new ApiException('validation_failed', 'لا يمكن تقييم طلب لم يكتمل تسليمه بعد', 422);
                }

                $user->ratings()->updateOrCreate(
                    ['order_id' => $order->id, 'rateable_type' => 'restaurant', 'rateable_id' => $entry['restaurant_id']],
                    ['stars' => $entry['stars'], 'tags' => $entry['tags'] ?? null, 'comment' => $entry['comment'] ?? null],
                );

                $this->recomputeRatingAverage($order->restaurant);
            }

            foreach ($driverEntries as $driverId => $driverEntry) {
                // The captain's delivered sub-order when he carried more than one.
                $order = $group->orders()
                    ->where('driver_id', $driverId)
                    ->orderByRaw("status = 'delivered' desc")
                    ->orderBy('id')
                    ->first();

                if (! $order || $order->status !== OrderStatus::Delivered) {
                    throw new ApiException('validation_failed', 'لا يمكن تقييم كابتن لم يكمل التوصيل بعد', 422);
                }

                $user->ratings()->updateOrCreate(
                    ['order_id' => $order->id, 'rateable_type' => 'driver', 'rateable_id' => $driverId],
                    ['stars' => $driverEntry['stars'], 'tags' => $driverEntry['tags'] ?? null, 'comment' => $driverEntry['comment'] ?? null],
                );

                $this->recomputeRatingAverage($order->driver);
            }
        });
    }

    /** True when this basket has already been rated by the customer who owns it. */
    public static function isRated(OrderGroup $group): bool
    {
        return Rating::query()
            ->where('user_id', $group->user_id)
            ->whereIn('order_id', $group->orders()->select('id'))
            ->exists();
    }

    /**
     * The average the customer app and the browse screens read.
     *
     * Hidden ratings are excluded — an operator hiding an abusive review that still
     * counted towards the score was hiding the words and keeping the punishment.
     */
    private function recomputeRatingAverage($model): void
    {
        if ($model === null) {
            return;
        }

        $stats = $model->ratings()
            ->where('is_hidden', false)
            ->selectRaw('avg(stars) as avg, count(*) as cnt')
            ->first();

        /*
         * 🔴 `update()` here wrote **nothing**, and had since the endpoint was written.
         *
         * Neither `rating_avg` nor `rating_count` is in `$fillable` on `Restaurant` or
         * `Driver` — they are derived columns the platform owns, so keeping them out of
         * mass assignment is right — and Eloquent drops an unfillable key in silence. So
         * every rating in the history was stored and none of them ever moved a score: the
         * app's cards, `CUS-12`'s filters and `DispatchService`'s ranking have all been
         * reading the number the seeder put there.
         *
         * `forceFill` is the way a derived column is written, and it is what makes
         * «ولما يتقيّم يتحدّث التقييم على حسب المتوسط» true.
         */
        $model->forceFill([
            'rating_avg' => round((float) ($stats->avg ?? 0), 2),
            'rating_count' => (int) ($stats->cnt ?? 0),
        ])->save();
    }
}
