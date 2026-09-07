<?php

namespace App\Enums;

enum BannerLinkType: string
{
    case Restaurant = 'restaurant';

    /**
     * One dish, by `products.id`.
     *
     * Operator, 2026-09-07: «العروض دي ربطها بالمطاعم بحيث لما أجي أعمل طلب الآن يوديني
     * على المنتج اللي على العرض». A banner that shouts «خصم على البرجر» and lands on a
     * restaurant's front page has spent the customer's attention and asked them to go
     * looking for the thing it just advertised.
     */
    case Product = 'product';
    case Category = 'category';
    case Promo = 'promo';
    case Url = 'url';
    case None = 'none';
}
