<?php

namespace App\Http\Resources\Shared;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One errand, as every client reads it — customer, captain and admin alike.
 *
 * Shared rather than tripled because the shape is genuinely the same for all three and
 * the only difference is *which* phone number you are allowed to see. That is applied
 * here, once, from the caller's own type: an errand on the open board carries no contact
 * details at all, because a captain who has not accepted it has no business ringing
 * either end. They appear the moment it is theirs.
 *
 * The alternative — three resources — is how a redaction rule ends up enforced in two of
 * the three places it matters.
 */
class ErrandRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user?->type->value === 'admin';
        $isOwner = $user !== null && $user->id === $this->user_id;
        $isAssignedDriver = $user !== null
            && $this->driver !== null
            && $this->driver->user_id === $user->id;

        $mayCall = $isAdmin || $isOwner || $isAssignedDriver;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'pickup' => [
                'lat' => (float) $this->pickup_lat,
                'lng' => (float) $this->pickup_lng,
                'address_text' => $this->pickup_address_text,
                'contact_phone' => $mayCall ? $this->pickup_contact_phone : null,
            ],
            'dropoff' => [
                'lat' => (float) $this->dropoff_lat,
                'lng' => (float) $this->dropoff_lng,
                'address_text' => $this->dropoff_address_text,
                'contact_phone' => $mayCall ? $this->dropoff_contact_phone : null,
            ],

            'details' => $this->details,
            'distance_km' => (float) $this->distance_km,
            'offered_fee' => (float) $this->offered_fee,
            'suggested_fee' => $this->suggested_fee !== null ? (float) $this->suggested_fee : null,
            'purchases_amount' => (float) $this->purchases_amount,
            'purchases_note' => $this->purchases_note,
            'receipt_urls' => $this->receipt_urls ?? [],
            'total_due' => (float) $this->total_due,

            'customer' => $this->when($isAdmin || $isAssignedDriver, fn () => [
                'id' => $this->customer?->id,
                'name' => $this->customer?->name,
                'phone' => $mayCall ? $this->customer?->phone : null,
            ]),
            'driver' => $this->driver === null ? null : [
                'id' => $this->driver->id,
                'name' => $this->driver->user?->name,
                'phone' => $mayCall ? $this->driver->user?->phone : null,
                'vehicle_type' => $this->driver->vehicle_type,
                'rating_avg' => (float) $this->driver->rating_avg,
                'current_lat' => $this->driver->current_lat !== null ? (float) $this->driver->current_lat : null,
                'current_lng' => $this->driver->current_lng !== null ? (float) $this->driver->current_lng : null,
            ],

            'assigned_by' => $this->assigned_by,
            'cancelled_by' => $this->cancelled_by,
            'cancel_reason' => $this->cancel_reason,

            'created_at' => $this->created_at?->toIso8601String(),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'picked_up_at' => $this->picked_up_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'admin_notified_at' => $this->admin_notified_at?->toIso8601String(),

            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'status' => $event->status,
                'actor_role' => $event->actor_role,
                // Admin-only. An operator assigning an errand by hand writes «لم يقبله
                // أحد» or worse into this field, and it is a note *about* the job for
                // the platform — not a line for the captain it names or the customer it
                // is about. Same rule D-53 applies to a complaint's internal note.
                'note' => $isAdmin ? $event->note : null,
                'created_at' => $event->created_at?->toIso8601String(),
            ])),
        ];
    }
}
