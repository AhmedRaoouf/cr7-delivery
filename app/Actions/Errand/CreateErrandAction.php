<?php

namespace App\Actions\Errand;

use App\Enums\ErrandStatus;
use App\Exceptions\ApiException;
use App\Models\ErrandRequest;
use App\Models\User;
use App\Support\ErrandDispatcher;
use App\Support\ReviewAccess;
use Illuminate\Support\Facades\DB;

/**
 * `POST /customer/errands` — publish a point-to-point job to every available captain.
 *
 * The fee is not validated against the suggestion. A customer who offers 10 for a 12 km
 * ride will simply not be taken, and that is a better outcome than a form that refuses
 * them: the offer *is* the mechanism, and clamping it to a computed band would quietly
 * turn it back into a price list.
 *
 * What is validated is coverage (both ends, in `QuoteErrandAction`) and the one-open-job
 * rule below.
 */
class CreateErrandAction
{
    /** A customer may have one live errand at a time. */
    public const MAX_OPEN_PER_CUSTOMER = 1;

    public function __construct(
        private readonly QuoteErrandAction $quote,
        private readonly ErrandDispatcher $dispatcher,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(User $user, array $data): ErrandRequest
    {
        ReviewAccess::refuseRealWork($user);

        $open = ErrandRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('status', array_column(ErrandStatus::active(), 'value'))
            ->count();

        if ($open >= self::MAX_OPEN_PER_CUSTOMER) {
            throw new ApiException(
                'errand_already_open',
                'لديك مشوار جارٍ بالفعل — أنهِه أو ألغِه قبل طلب مشوار جديد',
                422,
            );
        }

        $quote = $this->quote->execute(
            (float) $data['pickup_lat'],
            (float) $data['pickup_lng'],
            (float) $data['dropoff_lat'],
            (float) $data['dropoff_lng'],
        );

        $errand = DB::transaction(function () use ($user, $data, $quote): ErrandRequest {
            $errand = ErrandRequest::create([
                // Reserved, then replaced with the id-derived reference in the same
                // transaction — the same two-step `OrderNumber` uses, and for the same
                // reason: the reference is the id and the id does not exist yet.
                'reference' => 'ERR-pending-'.$user->id.'-'.now()->getTimestampMs(),
                'user_id' => $user->id,
                'city_id' => $quote['city_id'],
                'service_zone_id' => $quote['service_zone_id'],
                'status' => ErrandStatus::Open,
                'pickup_lat' => $data['pickup_lat'],
                'pickup_lng' => $data['pickup_lng'],
                'pickup_address_text' => $data['pickup_address_text'],
                'pickup_contact_phone' => $data['pickup_contact_phone'] ?? null,
                'dropoff_lat' => $data['dropoff_lat'],
                'dropoff_lng' => $data['dropoff_lng'],
                'dropoff_address_text' => $data['dropoff_address_text'],
                'dropoff_contact_phone' => $data['dropoff_contact_phone'] ?? $user->phone,
                'details' => $data['details'],
                'distance_km' => $quote['distance_km'],
                'offered_fee' => $data['offered_fee'],
                'suggested_fee' => $quote['suggested_fee'],
            ]);

            $errand->update(['reference' => ErrandReference::forId($errand->id)]);
            $errand->record(ErrandStatus::Open, $user, 'customer');

            return $errand;
        });

        // Outside the transaction: a push that fails must not roll back a published job.
        $this->dispatcher->publish($errand);

        return $errand->fresh(['customer', 'driver.user']);
    }
}
