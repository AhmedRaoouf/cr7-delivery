<?php

namespace App\Enums;

/** doc 22 §4 — direct orders only: who collects the food money. */
enum FoodPayment: string
{
    case PaidAtRestaurant = 'paid_at_restaurant';
    case CollectByDriver = 'collect_by_driver';
}
