<?php

namespace App\Enums;

/** doc 03 §7 — gateway-ready from day one; adding Paymob later requires no schema change. */
enum PaymentProvider: string
{
    case Manual = 'manual';
    case Paymob = 'paymob';
    case Fawry = 'fawry';
}
