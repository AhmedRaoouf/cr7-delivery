<?php

namespace App\Support;

use App\Models\OrderGroup;

/**
 * The customer-facing reference for an order group.
 *
 * Was `CR7-260904-34` — a date and the row id. Operator, 2026-09-07: «ارقام الطلبات تكون
 * squential بتبدا من 1000». A number read aloud down a phone line has to be short and has
 * to sound like a number; the date prefix made every reference eleven digits of which six
 * were the same for everyone who called that day.
 *
 * Derived from the row id rather than kept in a counter table: the id is already the
 * sequence, already unique, and already allocated inside the same transaction, so there
 * is no second row to lock and no way for two concurrent orders to be handed the same
 * reference. [OFFSET] only decides where the sequence starts reading.
 *
 * Existing rows keep the references they were given. The two shapes cannot collide —
 * the old one always carries two dashes — so nothing had to be migrated, and no receipt,
 * screenshot or support conversation from before today stopped resolving.
 */
class OrderNumber
{
    private const PREFIX = 'CR7-';

    /** Makes group 1 read as `CR7-1000` rather than `CR7-1`. */
    private const OFFSET = 999;

    public static function forGroup(int $groupId): string
    {
        return self::PREFIX.(self::OFFSET + $groupId);
    }

    /** A sub-order is its group's reference plus its position: `CR7-1043-2`. */
    public static function forSubOrder(string $groupNumber, int $sequence): string
    {
        return $groupNumber.'-'.$sequence;
    }

    /** The reservation written before the id exists; replaced in the same transaction. */
    public static function pending(string $idempotencyKey): string
    {
        return self::PREFIX.'pending-'.$idempotencyKey;
    }

    /** The next reference that would be handed out — for previews, never for storage. */
    public static function peek(): string
    {
        return self::forGroup((int) (OrderGroup::query()->max('id') ?? 0) + 1);
    }
}
