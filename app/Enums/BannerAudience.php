<?php

namespace App\Enums;

/**
 * Who a home banner is for.
 *
 * Operator, 2026-09-08: «خلي اول اورد للحساب توصيل مجاني واستخدمها بقا في العروض اللي
 * بتظهر اول ما تدخل الرئيسيه». An offer that is only true for a new account must not be
 * shown to an account that has already ordered — a slide promising free delivery to
 * somebody who will be charged for it is worse than no slide at all.
 *
 * The artwork and the copy stay the operator's: this says *whom* the slide is shown to,
 * never what it says.
 */
enum BannerAudience: string
{
    case All = 'all';

    /** Only an account whose next order would be its first — see `FirstOrderPerk`. */
    case FirstOrder = 'first_order';

    /** Only an account that has ordered before. The other half of the same split. */
    case Returning = 'returning';
}
