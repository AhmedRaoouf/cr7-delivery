<?php

namespace App\Enums;

/**
 * The five states of an errand — «اطلب كابتن».
 *
 * There is no `accepted` distinct from `assigned` and no restaurant leg at all: the
 * captain who takes it goes straight to the pickup. `open` covers both «nobody has
 * seen it yet» and «nobody has taken it yet», which is why the escalation to the admin
 * is a timestamp on the row rather than a state — an errand the operator has been told
 * about is still open, and must still be takeable by any captain.
 */
enum ErrandStatus: string
{
    case Open = 'open';
    case Assigned = 'assigned';
    case PickedUp = 'picked_up';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public static function active(): array
    {
        return [self::Open, self::Assigned, self::PickedUp];
    }

    public function isTerminal(): bool
    {
        return $this === self::Delivered || $this === self::Cancelled;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'بانتظار كابتن',
            self::Assigned => 'الكابتن في الطريق إلى الاستلام',
            self::PickedUp => 'استلم الكابتن الطلب',
            self::Delivered => 'تم التسليم',
            self::Cancelled => 'ملغى',
        };
    }
}
