<?php

namespace App\Enums;

enum ComplaintUserRole: string
{
    case Customer = 'customer';
    case Driver = 'driver';
    case Restaurant = 'restaurant';
}
