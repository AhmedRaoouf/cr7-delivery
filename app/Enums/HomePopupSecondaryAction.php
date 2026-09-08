<?php

namespace App\Enums;

/**
 * What the second button on a home popup does.
 *
 * «يحط زرار، يحط زرارين» — the operator decides there is a second button at all, and
 * this decides what it means. The X is always a `dismiss` and is never a row here.
 */
enum HomePopupSecondaryAction: string
{
    /** «ليس الآن» — closes this card and moves the queue on, exactly like the X. */
    case Dismiss = 'dismiss';

    /** «لاحقًا» — closes the whole queue until tomorrow, not just this card. */
    case Snooze = 'snooze';

    /** A second way to the same destination — «تصفح العروض» beside «اطلب الآن». */
    case Link = 'link';
}
