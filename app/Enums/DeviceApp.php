<?php

namespace App\Enums;

/** The `X-App` header value (doc 08 §1) — customer/driver share the "app" binary. */
enum DeviceApp: string
{
    case Customer = 'customer';
    case Driver = 'driver';
    case Restaurant = 'restaurant';
    case Admin = 'admin';
}
