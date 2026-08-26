<?php

namespace App\Enums;

/** doc 03 §6.1 — the discriminator between a marketplace order and a delivery-only order. */
enum OrderOrigin: string
{
    case App = 'app';
    case RestaurantDirect = 'restaurant_direct';
}
