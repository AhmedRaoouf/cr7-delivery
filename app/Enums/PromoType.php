<?php

namespace App\Enums;

enum PromoType: string
{
    case Fixed = 'fixed';
    case Percent = 'percent';
    case FreeDelivery = 'free_delivery';
}
