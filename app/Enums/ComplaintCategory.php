<?php

namespace App\Enums;

enum ComplaintCategory: string
{
    case OrderIssue = 'order_issue';
    case LateDelivery = 'late_delivery';
    case MissingItem = 'missing_item';
    case FoodQuality = 'food_quality';
    case DriverBehaviour = 'driver_behaviour';
    case Payment = 'payment';
    case AppProblem = 'app_problem';
    case Other = 'other';

    /**
     * doc 24 §4.3 / FR-CMP-05: order_issue, missing_item and food_quality are
     * always mirrored to the restaurant. late_delivery is Admin's discretion
     * (settings.support.mirror_late_delivery). Everything else never mirrors.
     */
    public function mirrorsToRestaurantByDefault(): bool
    {
        return match ($this) {
            self::OrderIssue, self::MissingItem, self::FoodQuality => true,
            default => false,
        };
    }

    public function canEverMirror(): bool
    {
        return $this !== self::DriverBehaviour
            && $this !== self::Payment
            && $this !== self::AppProblem
            && $this !== self::Other;
    }
}
