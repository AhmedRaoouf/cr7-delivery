<?php

namespace Tests\Feature;

use App\Support\MenuCategoryIcon;
use Tests\TestCase;

/**
 * Every menu tab gets a picture, including the ones nobody has typed yet.
 *
 * The rules are ordered, and the order is the whole design: «شوربة وطواجن» is a soup
 * before it is a tagine, «الفطير الحلو» is a dessert before it is a pizza, and «وجبات
 * جمبري بلدي» is seafood before it is a meal. A rule list that happened to work on the
 * eleven shops seeded today would still be wrong the first time an operator writes
 * «وجبات سمك».
 */
class MenuCategoryIconTest extends TestCase
{
    public static function categories(): array
    {
        return [
            // The eleven seeded shops, in their own words.
            ['المنيو', 'meal'],
            ['سندوتشات', 'sandwich'],
            ['أطباق أرز', 'rice'],
            ['فتة الكشري', 'rice'],
            ['شوربة وطواجن', 'soup'],
            ['ركن الطواجن الفلاحي', 'soup'],
            ['وجبات جمبري بلدي', 'seafood'],
            ['وجبات سمك فيليه', 'seafood'],
            ['ركن المشويات', 'grill'],
            ['الحواوشي', 'grill'],
            ['الفراخ', 'chicken'],
            ['الكبدة', 'chicken'],
            ['البرجر', 'burger'],
            ['البطاطس', 'fries'],
            ['بيتزا إيطالي', 'pizza'],
            ['الإسباجيتي', 'pasta'],
            ['المكرونة', 'pasta'],
            ['ركن السلطات', 'salad'],
            ['الفول والفلافل', 'breakfast'],
            ['مشروبات ساخنة', 'coffee'],
            ['إسبريسو', 'coffee'],
            ['عصائر فريش', 'drink'],
            ['ميلك شيك', 'drink'],
            ['آيس كريم', 'dessert'],
            ['الإضافات', 'extras'],

            // The two that are ordering traps.
            ['الفطير الحلو', 'dessert'],
            ['فطير شرقي', 'pizza'],

            // The shops that are not restaurants.
            ['مسكنات وخافضات حرارة', 'pharmacy'],
            ['أمومة وطفل', 'pharmacy'],
            ['ألبان وأجبان', 'grocery'],
            ['منظفات', 'grocery'],

            // English, because a bilingual menu is allowed.
            [null, 'meal', 'Family meals'],
            [null, 'seafood', 'Fish dishes'],

            // Nothing recognisable still gets a picture rather than a blank tab.
            ['ركن رمضان', 'meal'],
        ];
    }

    /**
     * @dataProvider categories
     */
    public function test_a_category_name_earns_its_icon(?string $ar, string $expected, ?string $en = null): void
    {
        $this->assertSame($expected, MenuCategoryIcon::slugFor($ar, $en), $ar ?? $en ?? '');
    }

    public function test_the_operators_own_upload_always_wins(): void
    {
        $uploaded = 'https://cr7-delivery.com/storage/categories/abc.png';

        $this->assertSame($uploaded, MenuCategoryIcon::resolve($uploaded, 'سندوتشات'));
        $this->assertStringEndsWith(
            'catalog/menu-icons/sandwich.png',
            (string) MenuCategoryIcon::resolve(null, 'سندوتشات'),
        );
    }

    public function test_every_rule_points_at_artwork_that_ships(): void
    {
        foreach (self::categories() as [$ar, $expected]) {
            $this->assertFileExists(
                public_path("catalog/menu-icons/{$expected}.png"),
                "the {$expected} icon is missing from public/catalog/menu-icons",
            );
        }
    }
}
