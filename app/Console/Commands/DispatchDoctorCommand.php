<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Setting;
use App\Support\DispatchService;
use App\Support\Geo;
use Illuminate\Console\Command;

/**
 * Why is this order not on a captain's phone?
 *
 * Every answer to that question lived inside one `->where()` chain, and a query
 * that returns nothing looks exactly like a query nobody ran. On 2026-09-12 the
 * operator reported an order sitting on «جاهز» with captains online and nothing
 * reaching them, and settling it meant reading five columns across two tables by
 * hand. This prints them, one line per captain, with the gate that stopped each.
 *
 * Read-only. It never offers, never writes and never changes a status — run it
 * on production with no more thought than `git status`.
 */
class DispatchDoctorCommand extends Command
{
    protected $signature = 'cr7:dispatch-doctor
        {order? : an order id or number — defaults to the oldest one still looking}';

    protected $description = 'Say, captain by captain, why an order has not been offered';

    public function handle(): int
    {
        $order = $this->order();

        if ($order === null) {
            $this->info('no order is waiting for a captain');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line(sprintf(
            'order %s  #%d  status=%s  zone=%s(%s)  driver=%s',
            $order->order_number,
            $order->id,
            $order->status->value,
            $order->serviceZone?->name_ar ?? '—',
            $order->service_zone_id ?? '—',
            $order->driver_id ?? '—',
        ));
        $this->line(sprintf(
            'ready_at=%s  round=%d  last_offer=%s  attempts=%d',
            $order->ready_at ?? '—',
            (int) $order->dispatch_round,
            $order->dispatch_last_offer_at ?? '—',
            $order->driverAttempts()->count(),
        ));

        $settings = Setting::query()
            ->where('key', 'like', 'dispatch.%')
            ->orderBy('key')
            ->pluck('value', 'key');

        $this->line('');
        foreach (['dispatch.offer_to_all_online', 'dispatch.allow_neighbour_zones', 'dispatch.radius_km', 'dispatch.location_stale_seconds', 'dispatch.rebroadcast_interval_seconds'] as $key) {
            $this->line(sprintf('  %-42s %s', $key, $settings[$key] ?? '(not set)'));
        }

        // `->value`, because `result` is an `OrderDriverAttemptResult` and a table
        // cell has to be a string. Cast where it is read, not in the model: the
        // enum is what every other caller wants.
        $attempts = $order->driverAttempts()
            ->get(['driver_id', 'result'])
            ->mapWithKeys(fn ($attempt) => [
                $attempt->driver_id => $attempt->result instanceof \BackedEnum
                    ? $attempt->result->value
                    : (string) $attempt->result,
            ]);
        $rows = [];

        foreach (Driver::query()->with(['user:id,name', 'assignedZones:id'])->get() as $driver) {
            $rows[] = [
                $driver->id,
                mb_substr($driver->user?->name ?? '—', 0, 18),
                $this->verdict($driver, $order),
                $driver->assignedZones->isEmpty()
                    ? 'أي نطاق'
                    : $driver->assignedZones->pluck('id')->implode(','),
                $driver->location_updated_at === null
                    ? 'لا يوجد'
                    : (int) $driver->location_updated_at->diffInSeconds(now()).'s',
                $this->distance($driver, $order),
                $attempts[$driver->id] ?? '—',
            ];
        }

        $this->table(
            ['id', 'اسم', 'الحالة', 'نطاقات', 'آخر إشارة', 'كم', 'عُرض عليه'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * The gates, in the order {@see DispatchService::dispatchRound()}
     * applies them — the first one that fails is the whole answer.
     */
    private function verdict(Driver $driver, Order $order): string
    {
        return match (true) {
            ! $driver->is_active => 'موقوف',
            ! $driver->is_online => 'غير متصل',
            ! $driver->is_available => 'مشغول (is_available)',
            $driver->active_order_id !== null => 'على طلب '.$driver->active_order_id,
            default => 'مرشّح',
        };
    }

    private function distance(Driver $driver, Order $order): string
    {
        if (! $driver->hasFix() || $order->restaurant === null) {
            return '—';
        }

        return number_format(Geo::haversineKm(
            (float) $driver->current_lat,
            (float) $driver->current_lng,
            (float) $order->restaurant->latitude,
            (float) $order->restaurant->longitude,
        ), 2);
    }

    private function order(): ?Order
    {
        $argument = $this->argument('order');

        $query = Order::query()->with(['restaurant', 'serviceZone']);

        if ($argument !== null) {
            return $query
                ->where('id', $argument)
                ->orWhere('order_number', $argument)
                ->first();
        }

        return $query
            ->whereIn('status', [
                OrderStatus::Ready,
                OrderStatus::SearchingDriver,
                OrderStatus::WaitingForDriver,
            ])
            ->whereNull('driver_id')
            ->oldest()
            ->first();
    }
}
