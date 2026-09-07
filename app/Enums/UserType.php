<?php

namespace App\Enums;

/** doc 12 §1 — fixes which client a user may log into. Never inferred from X-Role. */
enum UserType: string
{
    case Customer = 'customer';
    case Driver = 'driver';
    case Restaurant = 'restaurant';
    case Admin = 'admin';

    /** For the one sentence that has to tell somebody which kind of account their phone already is. */
    public function label(): string
    {
        return match ($this) {
            self::Customer => 'حساب عميل',
            self::Driver => 'حساب كابتن',
            self::Restaurant => 'حساب مطعم',
            self::Admin => 'حساب إدارة',
        };
    }
}
