<?php

namespace App\Support;

use App\Actions\Errand\ErrandLifecycleAction;
use App\Enums\ErrandStatus;
use App\Enums\UserType;
use App\Models\Driver;
use App\Models\ErrandRequest;
use App\Models\User;
use App\Support\Notifications\NotificationDispatcher;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who hears about an errand, and when.
 *
 * Nothing like `DispatchService`, on purpose. A food order is *offered* to the nearest
 * three captains in rounds, because the platform has already priced it and is choosing
 * who earns it. An errand is *published*: the customer named a fee, and whether it is
 * worth riding for is the captain's call, not a ladder's. Operator, 2026-09-07: «هيبعت
 * لكل المتاحين الموجودين… زي أوبر كده».
 *
 * So there is no round, no radius, no TTL and no offer row. Every available captain is
 * told once, the request sits on their board until somebody takes it, and the first
 * accept wins under a row lock.
 *
 * The one timed behaviour is the escalation: after `errands.escalate_after_minutes`
 * — the panel's, like every other number here — with no
 * taker, the operators are notified. That does **not** close the request — an errand the
 * admin has been told about is still open and still takeable, and `admin_notified_at`
 * exists so the sweeper says it once rather than every minute.
 */
class ErrandDispatcher
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    /**
     * Tell every available captain that a new errand is on the board.
     *
     * @param  bool  $onlyNew  skip captains already told about this request — what the
     *                         every-minute re-publish uses, so a captain who has been
     *                         looking at it for ten minutes is not rung ten times.
     */
    public function publish(ErrandRequest $errand, bool $onlyNew = false): int
    {
        $alreadyTold = array_map('intval', $errand->notified_driver_ids ?? []);
        // A captain who was offered this errand by an operator and said no is not a
        // candidate for the broadcast either — the board would put back in front of them
        // the exact thing they just declined.
        $declined = array_map('intval', $errand->declined_driver_ids ?? []);

        $recipients = $this->availableDrivers()
            ->reject(fn (Driver $driver) => in_array((int) $driver->id, $declined, true))
            ->when($onlyNew, fn ($drivers) => $drivers->reject(
                fn (Driver $driver) => in_array((int) $driver->id, $alreadyTold, true),
            ));

        foreach ($recipients as $driver) {
            $this->notifications->send(
                $driver->user,
                'errand.new',
                [
                    'pickup' => $errand->pickup_address_text,
                    'dropoff' => $errand->dropoff_address_text,
                    'distance' => number_format((float) $errand->distance_km, 1),
                    'fee' => self::money($errand->offered_fee),
                ],
                deepLink: 'cr7://errands/'.$errand->id,
                data: ErrandLifecycleAction::routing($errand),
                channel: 'offers',
            );
        }

        $errand->forceFill([
            'notified_driver_ids' => array_values(array_unique(array_merge(
                $alreadyTold,
                $recipients->map(fn (Driver $driver) => (int) $driver->id)->all(),
            ))),
            'last_published_at' => now(),
        ])->save();

        return $recipients->count();
    }

    /**
     * The every-minute re-publish. Operator, 2026-09-08: «حاول تخليها continuous يعني
     * مثلاً كل دقيقة تتشيك… تقوم بعت تاني، فاهم؟ وهكذا».
     *
     * `publish()` ran once, at creation, against whoever happened to be online in that
     * second. On a town with seven captains that is frequently nobody, and the request
     * then sat on a board no phone had been told to open. This asks again — only the
     * captains who have not already been told, so the ones who have been staring at it
     * are left alone.
     *
     * @return int captains newly told, across every open request
     */
    public function republishOpen(): int
    {
        $interval = self::republishIntervalMinutes();

        $open = ErrandRequest::query()
            ->where('status', ErrandStatus::Open->value)
            ->whereNull('driver_id')
            ->where(fn ($q) => $q
                ->whereNull('last_published_at')
                ->orWhere('last_published_at', '<=', now()->subMinutes($interval)))
            // An errand from last week is an operator's problem, not a dispatcher's —
            // the same cutoff `cr7:tick` applies to a stranded order.
            ->where('created_at', '>=', now()->subDay())
            ->limit(50)
            ->get();

        $told = 0;

        foreach ($open as $errand) {
            $told += $this->publish($errand, onlyNew: true);
        }

        return $told;
    }

    /**
     * The escalation sweep — called by `cr7:tick`.
     *
     * Deliberately notifies operators by `users.type`, not by permission: this is a
     * «somebody look at this» ping and the alert dock is where authority is applied. An
     * operator without `orders.view` sees a notification and an empty list, which is a
     * smaller failure than the request sitting unseen because the permission catalogue
     * and the notification list disagreed.
     */
    public function escalateStranded(): int
    {
        $cutoff = now()->subMinutes(self::escalateAfterMinutes());

        $stranded = ErrandRequest::query()
            ->where('status', ErrandStatus::Open->value)
            ->whereNull('driver_id')
            ->whereNull('admin_notified_at')
            ->where('created_at', '<=', $cutoff)
            ->limit(50)
            ->get();

        if ($stranded->isEmpty()) {
            return 0;
        }

        $admins = User::query()->where('type', UserType::Admin)->where('is_active', true)->get();

        foreach ($stranded as $errand) {
            foreach ($admins as $admin) {
                $this->notifications->send($admin, 'errand.no_driver_admin', [
                    'minutes' => self::escalateAfterMinutes(),
                    'reference' => $errand->reference,
                    'pickup' => $errand->pickup_address_text,
                    'dropoff' => $errand->dropoff_address_text,
                    'fee' => self::money($errand->offered_fee),
                ], deepLink: '/errands/'.$errand->id, data: ErrandLifecycleAction::routing($errand));
            }

            // Stamped whether or not any admin existed to tell, so a deployment with no
            // active operators does not re-scan the same rows every minute forever.
            $errand->forceFill(['admin_notified_at' => now()])->save();
        }

        return $stranded->count();
    }

    /**
     * Online, carrying no orders, and seen recently enough to be somewhere real.
     *
     * @return Collection<int, Driver>
     */
    private function availableDrivers()
    {
        $staleSeconds = DriverLiveness::staleSeconds();

        return Driver::query()
            ->with('user')
            ->where('is_active', true)
            ->where('is_online', true)
            // doc 63 gate 4, from the errand's side: a captain carrying orders is offered
            // no errands. Read from the trip itself rather than from `is_available`, which
            // is only a copy of it.
            ->whereDoesntHave('orders', fn ($q) => $q->whereIn('status', DriverTrip::STATES))
            ->where(fn ($q) => $q->whereNull('location_updated_at')
                ->orWhere('location_updated_at', '>=', now()->subSeconds($staleSeconds)))
            ->get();
    }

    /** Same shape as `OrderNotifications::money` — the copy tables share one currency. */
    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 2).' ج.م';
    }

    /**
     * Both intervals come from the catalogue, which already declares their floors — ‪1‬
     * minute each — and already reads the declared default for a row outside them. The
     * `max(1, …)` these carried was a second floor with no screen behind it, and the
     * literal `1` beside it a second copy of the default.
     */
    private static function republishIntervalMinutes(): int
    {
        return SettingsCatalog::int('errands.republish_interval_minutes');
    }

    private static function escalateAfterMinutes(): int
    {
        return SettingsCatalog::int('errands.escalate_after_minutes');
    }
}
