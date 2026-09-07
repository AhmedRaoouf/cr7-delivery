<?php

namespace App\Enums;

/**
 * The six states of an errand — «اطلب كابتن».
 *
 * There is no `accepted` distinct from `assigned` and no restaurant leg at all: the
 * captain who takes it goes straight to the pickup. `open` covers both «nobody has
 * seen it yet» and «nobody has taken it yet», which is why the escalation to the admin
 * is a timestamp on the row rather than a state — an errand the operator has been told
 * about is still open, and must still be takeable by any captain.
 *
 * `offered` is the sixth, added 2026-09-07: «إسناد أي إسناد في الدنيا لازم يتبعت
 * notification وصفحة قبول ورفض». An operator's assignment is a question, and until it is
 * answered the errand has no captain — which is a different thing from being on the open
 * board, because nobody else may take it while one captain is holding it.
 */
enum ErrandStatus: string
{
    case Open = 'open';
    /** An operator handed it to one captain and that captain has not answered yet. */
    case Offered = 'offered';
    case Assigned = 'assigned';
    case PickedUp = 'picked_up';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public static function active(): array
    {
        return [self::Open, self::Offered, self::Assigned, self::PickedUp];
    }

    public function isTerminal(): bool
    {
        return $this === self::Delivered || $this === self::Cancelled;
    }

    /** Nobody owns it yet — it is on the board or on one captain's screen awaiting an answer. */
    public function isUnclaimed(): bool
    {
        return $this === self::Open || $this === self::Offered;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'بانتظار كابتن',
            self::Offered => 'بانتظار رد الكابتن',
            self::Assigned => 'الكابتن في الطريق إلى الاستلام',
            self::PickedUp => 'استلم الكابتن الطلب',
            self::Delivered => 'تم التسليم',
            self::Cancelled => 'ملغى',
        };
    }
}
