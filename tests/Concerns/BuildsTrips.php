<?php

namespace Tests\Concerns;

use App\Actions\Customer\CreateOrderAction;
use App\Actions\Restaurant\MarkOrderReadyAction;
use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * doc 63 fixtures: captains, one- and two-place baskets, and the kitchen's two moves.
 *
 * The places sit in the tests' Gamasa. At this latitude ‪0.001°‬ of latitude is about
 * ‪111‬ m, so a place ‪0.009°‬ north of another is a kilometre away — inside the ‪1.5‬ km that
 * joins a trip — and one ‪0.03°‬ north is three kilometres away, outside it.
 */
trait BuildsTrips
{
    protected function captain(string $name = 'كابتن رامي', float $lat = 31.3845, float $lng = 31.4845, array $attributes = []): Driver
    {
        $user = User::factory()->create(['type' => 'driver', 'name' => $name, 'is_active' => true]);

        return Driver::query()->create([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'vehicle_plate' => 'ط ر ق 123',
            'earning_type' => 'percent_of_delivery_fee',
            'earning_value' => 0,
            'is_active' => true,
            'is_online' => true,
            'is_available' => true,
            'current_lat' => $lat,
            'current_lng' => $lng,
            'location_updated_at' => now(),
            ...$attributes,
        ])->load('user');
    }

    /**
     * One basket from each of these places, in `group_sequence` order. Free delivery on a
     * first order is off unless a test asks for it, so the earning is a real number.
     *
     * @param  list<Restaurant>  $places
     * @return Collection<int, Order>
     */
    protected function basket(array $places, ?User $customer = null, string $payment = 'cash'): Collection
    {
        if (count($places) > 2) {
            Settings::set('cart.max_restaurants', count($places));
        }

        $customer ??= $this->makeCustomer();
        $address = $customer->addresses()->first() ?? $this->makeAddress($customer);

        $lines = [];
        foreach ($places as $place) {
            $lines[] = [$place, $this->makeProduct($place, 'صنف '.$place->name_ar, 100.0), 1];
        }

        $this->makeCart($customer, $lines);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => $payment],
            'idem-'.uniqid('', true),
        );

        return Order::query()->where('order_group_id', $group->id)->orderBy('group_sequence')->get();
    }

    /** The kitchen has started: a countdown of `$minutes`. */
    protected function preparing(Order $order, int $minutes = 15): Order
    {
        $order->update([
            'status' => OrderStatus::Preparing,
            'preparing_at' => now(),
            'prep_due_at' => now()->addMinutes($minutes),
            'prep_minutes' => $minutes,
        ]);

        return $order->fresh();
    }

    /** «الطلب جاهز» from the place — the same action its button and the prep timer call. */
    protected function ready(Order $order): Order
    {
        $order = $order->status === OrderStatus::Preparing ? $order : $this->preparing($order);

        return app(MarkOrderReadyAction::class)->execute($order->restaurant->owner, $order->fresh());
    }

    protected function asCaptain(Driver $driver): static
    {
        Sanctum::actingAs($driver->user);

        return $this->withHeaders(['X-App' => 'app', 'Accept-Language' => 'ar']);
    }

    protected function acceptAs(Driver $driver, Order $order): TestResponse
    {
        return $this->asCaptain($driver)->postJson("/api/v1/driver/orders/{$order->id}/accept");
    }

    protected function rejectAs(Driver $driver, Order $order): TestResponse
    {
        return $this->asCaptain($driver)->postJson("/api/v1/driver/orders/{$order->id}/reject", ['reason_code' => 'busy']);
    }

    /** @return list<int> the order ids with an open offer in this captain's hand */
    protected function openOffers(Driver $driver): array
    {
        return $driver->driverAttempts()->where('result', 'sent')->orderBy('order_id')->pluck('order_id')->map(fn ($id) => (int) $id)->all();
    }
}
