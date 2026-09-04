<?php

namespace App\Support\Notifications;

/**
 * The bidi isolate every number in an Arabic sentence needs (doc 13 §4, CLAUDE.md
 * «Digits and bidi»).
 *
 * Arabic is right-to-left and digits are left-to-right, so a run of digits inside
 * an Arabic sentence is a direction change the renderer has to resolve. Left to
 * itself it resolves by the surrounding characters, and the surrounding characters
 * of «كود التسليم 4821.» include that full stop — which the algorithm treats as
 * part of the left-to-right run and moves. The visible result is a reordered
 * number.
 *
 * That is not cosmetic here. `order.picked_up` carries the delivery code, and a
 * code shown to the customer in the wrong order is a code the captain will be
 * told is wrong at the door, on a delivery that is otherwise correct. It is the
 * one bug in the delivery-code feature that produces no error anywhere.
 *
 * U+2066 LEFT-TO-RIGHT ISOLATE … U+2069 POP DIRECTIONAL ISOLATE fences the run so
 * neighbouring punctuation cannot join it. FSI/PDI rather than the older
 * LRE/PDF: the isolate forms do not leak their direction into the rest of the
 * paragraph.
 */
class Isolate
{
    private const LRI = "\u{2066}";

    private const PDI = "\u{2069}";

    public static function ltr(string $value): string
    {
        return $value === '' ? '' : self::LRI.$value.self::PDI;
    }

    /**
     * Wraps only what needs it.
     *
     * A restaurant name is Arabic and must not be isolated — that would pin it
     * left-to-right inside a right-to-left sentence. A price, a count, an order
     * number and a code must be. The test is «does this contain a digit», which
     * is what makes a mixed string like «‪CR7-260904-1258‬» qualify.
     */
    public static function ltrIfNumeric(string $value): string
    {
        return preg_match('/\d/', $value) === 1 ? self::ltr($value) : $value;
    }
}
