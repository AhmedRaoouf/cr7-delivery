<?php

namespace App\Enums;

enum BannerLinkType: string
{
    case Restaurant = 'restaurant';
    case Category = 'category';
    case Promo = 'promo';
    case Url = 'url';
    case None = 'none';
}
