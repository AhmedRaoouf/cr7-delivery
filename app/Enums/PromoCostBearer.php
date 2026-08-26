<?php

namespace App\Enums;

/** FR-ORD-04c / FR-PAY-09 — who bears a promo discount. */
enum PromoCostBearer: string
{
    case Platform = 'platform';
    case Restaurant = 'restaurant';
}
