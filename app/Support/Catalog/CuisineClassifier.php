<?php

namespace App\Support\Catalog;

use App\Models\Cuisine;
use App\Models\MenuCategory;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\StoreCategory;
use App\Support\ArabicSearch;

/**
 * Doc 82 — which cuisine a dish belongs to, guessed from its own name and then from the
 * name of the menu section it sits in.
 *
 * The rules are keyed by the cuisine's `icon` slug (the fifteen seeded cuisines), so a
 * cuisine a place creates later is never guessed — it is chosen. A dish that no rule
 * names, and every product of a place that is not a restaurant or a café (a pharmacy's
 * medicine, a market's groceries), goes to the one fallback cuisine («أخرى»), which the
 * customer never sees as a filter.
 *
 * Two tiers, and the order is the tie-break: what the dish *is* ({@see self::FORMS})
 * beats what it is *made of* ({@see self::FILLINGS}) — «سندوتش كفتة» is a sandwich,
 * «تشيكن رانش» under «بيتزا» a pizza. A form is read on the name before the section; a
 * filling on the section before the name, because «وجبات بروستد» knows more than
 * «بوكس تشيكن». A section of extras and sauces is never a filter.
 */
final class CuisineClassifier
{
    /** Store categories whose products are dishes. Anything else is «أخرى». */
    private const FOOD_STORE_CATEGORIES = ['restaurants', 'cafes'];

    /** The slug a rule answers with to mean «no filter fits» — it stops the search. */
    public const OTHER = 'other';

    /**
     * A section of extras («الإضافات», «الصوصات») is not a dish anyone filters for.
     *
     * @var list<string>
     */
    private const EXTRAS = ['اضافات', 'اضافه', 'صوصات', 'صوص', 'sauces', 'extras', 'add-ons'];

    /**
     * What the dish *is*. Read on the dish's name first, then on its section's.
     * slug => words; a word matches a whole token after {@see ArabicSearch::normalize()},
     * with the article in front and a plural or feminine ending behind.
     *
     * @var array<string, list<string>>
     */
    private const FORMS = [
        'pizza' => ['بيتزا', 'pizza', 'بيتزه'],
        'burger' => ['برجر', 'برغر', 'burger', 'بيرجر', 'سماش'],
        'sandwich' => [
            'سندوتش', 'سندوتشات', 'ساندوتش', 'ساندوتشات', 'ساندويتش', 'سندويتش', 'sandwich', 'صاج', 'باجيت',
            'فينو', 'كيزر', 'تورتيلا', 'راب', 'wrap', 'رول', 'roll', 'حواوشي', 'كاساديا', 'quesadilla',
        ],
        'fateer' => ['فطير', 'فطيره', 'فطائر', 'مشلتت', 'feteer'],
        // Before «dessert»: «صنية الصحبة الحلوة» is a tray, not a sweet.
        'egyptian' => ['طاجن', 'طواجن', 'صينيه', 'صنيه', 'صواني', 'طاسه', 'طاسات'],
        'dessert' => [
            'حلويات', 'حلو', 'ديزرت', 'dessert', 'كنافه', 'بسبوسه', 'ام علي', 'رز بلبن', 'ارز باللبن', 'مهلبيه',
            'كيك', 'تورته', 'جاتوه', 'ايس كريم', 'جيلاتي', 'وافل', 'waffle', 'بان كيك', 'pancake', 'نوتيلا',
            'لوتس', 'شوكولاته', 'تشيز كيك', 'براونيز', 'تاياكي', 'كب كيك', 'قطايف', 'بلح الشام', 'زلابيه',
        ],
        'pastry' => ['معجنات', 'كريب', 'كريبات', 'crepe', 'كرواسون', 'سمبوسه', 'سمبوسك', 'بف', 'بريوش'],
        'koshari' => ['كشري', 'koshari', 'koshary'],
        'drink' => [
            'مشروبات', 'مشروب', 'عصير', 'عصائر', 'قهوه', 'كوفي', 'coffee', 'شاي', 'tea', 'نسكافيه', 'كابتشينو',
            'كابوتشينو', 'لاتيه', 'latte', 'اسبريسو', 'اسبرسو', 'espresso', 'موكا', 'ماكياتو', 'امريكانو', 'موهيتو',
            'سموذي', 'smoothie', 'ميلك شيك', 'شيك', 'بيبسي', 'كولا', 'كوكاكولا', 'سفن', 'ميرندا', 'مياه', 'صودا',
            'soda', 'فرابيه', 'فراب', 'هوت شوكليت', 'سحلب', 'ينسون', 'كركديه', 'ليمون نعناع', 'درينك', 'drink',
            'juice', 'cocktail', 'كوكتيل', 'ريد بول', 'سبرايت', 'فانتا', 'كاكاو', 'سوفت',
        ],
        // Pasta and rice have no cuisine of their own; saying so here stops «مكرونة
        // فراخ» from being filed under «دجاج».
        self::OTHER => [
            'مكرونه', 'مكرونات', 'باستا', 'pasta', 'اسباجيتي', 'سباجيتي', 'نجرسكو', 'نجريسكو', 'ريزو', 'الفريدو',
            'فورنو', 'بشاميل', 'فيتوتشيني', 'ماك اند تشيز',
        ],
    ];

    /**
     * What the dish is *made of*. Read on the section first — «وجبات بروستد», «المشويات»
     * say more than «بوكس تشيكن» — then on the name.
     *
     * @var array<string, list<string>>
     */
    private const FILLINGS = [
        'seafood' => [
            'سي فود', 'seafood', 'سمك', 'اسماك', 'جمبري', 'shrimp', 'كاليماري', 'سبيط', 'بلطي', 'بوري', 'دنيس',
            'قاروص', 'كابوريا', 'استاكوزا', 'سنجاري', 'تونه', 'سالمون', 'fish',
        ],
        'broast' => [
            'بروست', 'بروستد', 'broast', 'كريسبي', 'crispy', 'استربس', 'ستربس', 'استرابس', 'strips', 'زنجر',
            'zinger', 'فرايد تشيكن', 'fried chicken', 'ناشفيل', 'ناشفل', 'nashville', 'تندر', 'تندرز', 'مسحب', 'فيلر',
        ],
        'grill' => [
            'مشويات', 'مشوي', 'مشويه', 'grill', 'جريل', 'كباب', 'kebab', 'كفته', 'kofta', 'ريش', 'طرب', 'شيش',
            'تكه', 'فحم', 'نيفه', 'ستيك', 'steak',
        ],
        'oriental' => [
            'شرقي', 'شاورما', 'shawarma', 'فته', 'متبل', 'حمص', 'بابا غنوج', 'تبوله', 'فتوش', 'كبه', 'مندي',
            'كبسه', 'برياني', 'مقلوبه', 'خليجي', 'منسف', 'طاووق', 'مبكبكه',
        ],
        'chicken' => ['فراخ', 'فرخه', 'دجاج', 'تشيكن', 'chicken', 'بانيه', 'اجنحه', 'وينجز', 'wings', 'شوايه', 'شوايا'],
        'egyptian' => [
            'مصري', 'فول', 'طعميه', 'فلافل', 'مسقعه', 'محشي', 'محاشي', 'ملوخيه', 'باميه', 'كوارع', 'ممبار',
            'كبده', 'سجق', 'فلاحي', 'مطبخ', 'حمام', 'بط', 'عكاوي', 'رقاق', 'لحمه راس',
        ],
        'bakery' => ['مخبوزات', 'مخبوز', 'بقسماط', 'كعك', 'بيتي فور', 'بسكوت', 'bakery'],
    ];

    /**
     * The memo lives in the container, not in a static: a new application (a test, a
     * queue worker's next job) starts with an empty one, and a stale id can never be
     * written onto a dish.
     */
    private const MEMO = 'cr7.cuisine-classifier';

    /** Forget the memo — after cuisines are created or deleted. */
    public static function flush(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    /** @return \ArrayObject<string, mixed> */
    private static function memo(): \ArrayObject
    {
        if (! app()->resolved(self::MEMO) && ! app()->bound(self::MEMO)) {
            app()->instance(self::MEMO, new \ArrayObject);
        }

        return app(self::MEMO);
    }

    /**
     * The slug the rules give a dish — {@see self::OTHER} when a rule says none fits,
     * null when no rule spoke at all.
     */
    public static function guess(?string $nameAr, ?string $nameEn = null, ?string $sectionName = null): ?string
    {
        $name = trim(($nameAr ?? '').' '.($nameEn ?? ''));
        $section = $sectionName ?? '';

        if (self::match($section, [self::OTHER => self::EXTRAS]) !== null) {
            return self::OTHER;
        }

        return self::match($name, self::FORMS)
            ?? self::match($section, self::FORMS)
            ?? self::match($section, self::FILLINGS)
            ?? self::match($name, self::FILLINGS);
    }

    /** The cuisine id a product should carry when nobody chose one. */
    public static function idFor(Product $product): ?int
    {
        $fallback = self::fallbackId();

        $restaurant = $product->relationLoaded('restaurant')
            ? $product->restaurant
            : Restaurant::query()->find($product->restaurant_id, ['id', 'store_category_id']);

        if ($restaurant !== null && ! self::isFoodPlace($restaurant->store_category_id)) {
            return $fallback;
        }

        $section = $product->menu_category_id
            ? MenuCategory::query()->whereKey($product->menu_category_id)->value('name_ar')
            : null;

        $slug = self::guess($product->name_ar, $product->name_en, $section);

        return ($slug !== null && $slug !== self::OTHER ? (self::ids()[$slug] ?? null) : null) ?? $fallback;
    }

    public static function fallbackId(): ?int
    {
        $memo = self::memo();

        return $memo['fallback'] ??= Cuisine::query()->where('is_fallback', true)->value('id');
    }

    private static function isFoodPlace(?int $storeCategoryId): bool
    {
        if ($storeCategoryId === null) {
            return true;
        }

        $slug = StoreCategory::query()->whereKey($storeCategoryId)->value('slug');

        return $slug === null || in_array($slug, self::FOOD_STORE_CATEGORIES, true);
    }

    /** @return array<string, int> */
    private static function ids(): array
    {
        $memo = self::memo();

        return $memo['ids'] ??= Cuisine::query()
            ->whereNotNull('icon')
            ->where('is_fallback', false)
            ->pluck('id', 'icon')
            ->all();
    }

    /** @param  array<string, list<string>>  $rules */
    private static function match(string $text, array $rules): ?string
    {
        $haystack = ' '.ArabicSearch::normalize($text).' ';

        if (trim($haystack) === '') {
            return null;
        }

        foreach ($rules as $slug => $words) {
            foreach ($words as $word) {
                $needle = ArabicSearch::normalize($word);

                // A whole word, with the article or a joined preposition in front
                // («المشويات», «بالفراخ») and a plural or feminine ending behind it —
                // never a word's first letters, or «بط» would file «بطاطس» as Egyptian.
                if (preg_match('/(^|[\s\(\-\/،,&+])(ال|بال|وال|و|ب|لل)?'.preg_quote($needle, '/').'(ات|ه|ي|يه|ز|s|es)?(?=$|[\s\)\-\/،,&+.])/u', $haystack) === 1) {
                    return $slug;
                }
            }
        }

        return null;
    }
}
