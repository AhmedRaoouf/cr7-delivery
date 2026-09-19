<?php

namespace App\Enums;

/**
 * Every image field a picker can fill (doc 71 §3), and the usage each one demands.
 *
 * The one list clients and server share: the picker is asked for a `field`, the write
 * endpoints accept `<column>_media` and resolve it against the same field. Adding an image
 * field to the platform means adding a case here — nothing else decides validity.
 */
enum MediaField: string
{
    case ProductImage = 'product_image';
    case ProductGallery = 'product_gallery';
    case CategoryImage = 'category_image';
    case StoreCategoryImage = 'store_category_image';
    case StoreCategoryIcon = 'store_category_icon';
    case CuisineImage = 'cuisine_image';
    case CuisineIcon = 'cuisine_icon';
    case RestaurantLogo = 'restaurant_logo';
    case RestaurantCover = 'restaurant_cover';
    case BannerImage = 'banner_image';
    case PopupImage = 'popup_image';

    public function usage(): MediaUsage
    {
        return match ($this) {
            self::ProductImage, self::ProductGallery => MediaUsage::Product,
            self::CategoryImage, self::StoreCategoryImage, self::CuisineImage => MediaUsage::Category,
            self::StoreCategoryIcon, self::CuisineIcon => MediaUsage::Icon,
            self::RestaurantLogo => MediaUsage::Logo,
            self::RestaurantCover => MediaUsage::Cover,
            self::BannerImage, self::PopupImage => MediaUsage::Banner,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $f) => $f->value, self::cases());
    }
}
