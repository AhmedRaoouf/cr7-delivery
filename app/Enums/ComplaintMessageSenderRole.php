<?php

namespace App\Enums;

enum ComplaintMessageSenderRole: string
{
    case Customer = 'customer';
    case Driver = 'driver';
    case Restaurant = 'restaurant';
    case Admin = 'admin';
}
