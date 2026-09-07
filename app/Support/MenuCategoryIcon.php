<?php

namespace App\Support;

/**
 * A picture for every menu tab, without one image per menu row.
 *
 * Operator, 2026-09-07: «عندك المينيو، ساندوتشات، أطباق رز، شوربة وطواجن… كل تاب من اللي
 * فوق دول في كل مطعم يبقى ليه صورة».
 *
 * 85 categories across 11 shops, and «ركن المشويات», «المشويات» and «وجبات صواني
 * الدمياطي» all want the same picture. So the artwork is nineteen ideas rather than
 * eighty-five rows, matched by keyword — which is also the only way the *next* category,
 * the one an operator types tomorrow, gets a picture at all.
 *
 * Resolved at **read** time, never written into the column. `menu_categories.image` stays
 * the operator's own override: upload one and it wins, leave it null and this answers.
 * Baking the guess into the column would make a rename silently keep the old picture, and
 * would leave nothing to distinguish «the operator chose this» from «we guessed».
 *
 * Order matters. «شوربة وطواجن» must not be read as «طواجن» before it is read as
 * «شوربة», and «الفطير الحلو» is a dessert rather than a pizza, so the more specific
 * phrases are tried first.
 */
abstract class MenuCategoryIcon
{
    /** @var list<array{0: string, 1: list<string>}> icon slug, then the words that pick it */
    private const RULES = [
        ['dessert', ['حلو', 'حلوي', 'حلويات', 'كيك', 'وافل', 'آيس', 'ايس كريم', 'كريب', 'بسبوسة', 'كنافة', 'dessert', 'cake', 'waffle', 'ice cream']],
        ['coffee', ['قهوة', 'إسبريسو', 'اسبريسو', 'كابتشينو', 'نسكافيه', 'مشروبات ساخنة', 'ساخن', 'شاي', 'coffee', 'espresso', 'hot drink']],
        ['drink', ['مشروب', 'عصير', 'عصائر', 'ميلك', 'شيك', 'بارد', 'مياه', 'drink', 'juice', 'shake', 'beverage']],
        ['soup', ['شوربة', 'شوربه', 'حساء', 'طاجن', 'طواجن', 'soup', 'tagine']],
        ['seafood', ['سمك', 'أسماك', 'جمبري', 'سبيط', 'كاليماري', 'بحري', 'سي فود', 'seafood', 'fish', 'shrimp']],
        ['pizza', ['بيتزا', 'فطير', 'فطاير', 'pizza', 'pie']],
        ['pasta', ['مكرونة', 'مكرونه', 'باستا', 'إسباجيتي', 'اسباجيتي', 'بشاميل', 'pasta', 'spaghetti']],
        ['burger', ['برجر', 'برغر', 'بوكس', 'بوكسات', 'burger', 'box']],
        ['fries', ['بطاطس', 'بطاطا', 'fries', 'potato']],
        ['chicken', ['فراخ', 'دجاج', 'بروست', 'كبدة', 'ستربس', 'chicken', 'broast']],
        ['grill', ['مشوي', 'مشويات', 'كباب', 'كفتة', 'شرقي', 'خليجي', 'صواني', 'صينية', 'حواوشي', 'grill', 'kebab', 'bbq']],
        ['rice', ['أرز', 'ارز', 'رز', 'كشري', 'كشرى', 'فتة', 'rice', 'koshari']],
        ['sandwich', ['سندوتش', 'ساندوتش', 'سندوتشات', 'شاورما', 'كرسبي رول', 'sandwich', 'wrap', 'shawarma']],
        ['salad', ['سلطة', 'سلطات', 'مقبلات', 'salad', 'appetiser', 'appetizer', 'starter']],
        ['breakfast', ['فول', 'فلافل', 'طعمية', 'فطار', 'إفطار', 'breakfast', 'falafel']],
        ['pharmacy', ['دواء', 'أدوية', 'مسكن', 'برد', 'حساسية', 'معدة', 'هضم', 'طبية', 'عناية', 'أمومة', 'طفل', 'حفاض', 'pharmacy', 'medicine', 'medical', 'baby', 'care']],
        ['grocery', ['بقالة', 'ألبان', 'أجبان', 'منظفات', 'منزلية', 'مجمدات', 'سناكس', 'مثلجات', 'خضار', 'خضروات', 'فاكهة', 'grocery', 'pantry', 'dairy', 'cleaning', 'household', 'frozen', 'snack']],
        ['extras', ['إضافة', 'إضافات', 'اضافات', 'صوص', 'صوصات', 'extra', 'add-on', 'sauce']],
        // Last, because «وجبات» and «أطباق» appear inside half the phrases above.
        ['meal', ['وجبة', 'وجبات', 'أطباق', 'اطباق', 'قسم', 'ركن', 'المطبخ', 'المنيو', 'meal', 'menu', 'dish']],
    ];

    private const FOLDER = 'catalog/menu-icons';

    /** The fallback when nothing matches — a covered dish says «food» and nothing else. */
    private const DEFAULT = 'meal';

    /**
     * @param  string|null  $override  `menu_categories.image` — the operator's own upload.
     * @return string|null a URL, or null only when the shipped artwork is missing
     */
    public static function resolve(?string $override, ?string $nameAr, ?string $nameEn = null): ?string
    {
        if (filled($override)) {
            return $override;
        }

        return asset(self::FOLDER.'/'.self::slugFor($nameAr, $nameEn).'.png');
    }

    public static function slugFor(?string $nameAr, ?string $nameEn = null): string
    {
        $haystack = ArabicSearch::normalize(trim(($nameAr ?? '').' '.($nameEn ?? '')));

        foreach (self::RULES as [$slug, $words]) {
            foreach ($words as $word) {
                if ($word !== '' && str_contains($haystack, ArabicSearch::normalize($word))) {
                    return $slug;
                }
            }
        }

        return self::DEFAULT;
    }
}
