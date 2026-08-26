<?php

namespace App\Enums;

enum ComplaintResolutionOutcome: string
{
    case Refund = 'refund';
    case PartialRefund = 'partial_refund';
    case Redelivery = 'redelivery';
    case WarningRestaurant = 'warning_restaurant';
    case WarningDriver = 'warning_driver';
    case NoAction = 'no_action';
    case Rejected = 'rejected';
}
