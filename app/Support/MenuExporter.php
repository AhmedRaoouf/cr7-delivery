<?php

namespace App\Support;

use App\Models\MenuCategory;
use App\Models\Product;
use App\Models\Restaurant;
use BackedEnum;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One place's menu as a real `.xlsx` — «تصدير ملفات إكسل للمنيو أقسام ومنتجات».
 *
 * Three worksheets, each a flat table an operator can filter, sort and re-upload by hand:
 * the categories, the dishes (one row each, with its category by name and id), and the
 * option groups with their options (one row per option, keyed by the dish id). The ids are
 * the platform's own, so a row can be matched back to the admin screen.
 *
 * Streamed for the same reason as `ReportExporter`: shared hosting, 128 MB. Soft-deleted
 * rows are not in the file, because they are not on the menu.
 */
class MenuExporter
{
    public static function filename(Restaurant $restaurant, string $stamp): string
    {
        return "menu-{$restaurant->id}-{$stamp}.xlsx";
    }

    public static function stream(Restaurant $restaurant, string $stamp): StreamedResponse
    {
        return new StreamedResponse(function () use ($restaurant): void {
            $options = new Options;
            $options->setColumnWidth(26, ...range(1, 20));

            $writer = new Writer($options);
            $writer->openToFile('php://output');

            $header = (new Style)->setFontBold()->setCellAlignment(CellAlignment::CENTER);
            $rtl = fn () => (new SheetView)->setRightToLeft(true);

            $writer->getCurrentSheet()->setName('الأقسام')->setSheetView($rtl());
            $writer->addRow(Row::fromValues(['رقم القسم', 'الاسم بالعربية', 'الاسم بالإنجليزية', 'الترتيب', 'ظاهر', 'عدد الأصناف'], $header));

            $categories = $restaurant->menuCategories()->withCount('products')->orderBy('sort_order')->orderBy('id')->get();
            foreach ($categories as $category) {
                /** @var MenuCategory $category */
                $writer->addRow(Row::fromValues(self::cells([
                    $category->id, $category->name_ar, $category->name_en, $category->sort_order,
                    $category->is_active ? 'نعم' : 'لا', (int) $category->products_count,
                ])));
            }
            $categoryNames = $categories->pluck('name_ar', 'id');

            $writer->addNewSheetAndMakeItCurrent()->setName('الأصناف')->setSheetView($rtl());
            $writer->addRow(Row::fromValues([
                'رقم الصنف', 'رقم القسم', 'القسم', 'الاسم بالعربية', 'الاسم بالإنجليزية',
                'الوصف بالعربية', 'الوصف بالإنجليزية', 'السعر', 'سعر بعد الخصم', 'النوع',
                'متاح', 'مميز', 'وقت التحضير (دقيقة)', 'الترتيب', 'الصورة',
            ], $header));

            $products = Product::query()->where('restaurant_id', $restaurant->id)
                ->with(['cuisine:id,name_ar', 'optionGroups.options'])
                ->orderBy('menu_category_id')->orderBy('sort_order')->orderBy('id');

            $groups = [];
            foreach ($products->lazy(200) as $product) {
                /** @var Product $product */
                $writer->addRow(Row::fromValues(self::cells([
                    $product->id, $product->menu_category_id, $categoryNames[$product->menu_category_id] ?? null,
                    $product->name_ar, $product->name_en, $product->description_ar, $product->description_en,
                    (float) $product->price, $product->discount_price === null ? null : (float) $product->discount_price,
                    $product->cuisine?->name_ar, $product->is_available ? 'نعم' : 'لا', $product->is_featured ? 'نعم' : 'لا',
                    $product->prep_minutes, $product->sort_order, $product->image,
                ])));

                foreach ($product->optionGroups as $group) {
                    foreach ($group->options as $option) {
                        $groups[] = self::cells([
                            $product->id, $product->name_ar, $group->id, $group->name_ar, $group->name_en,
                            $group->selection_type, $group->price_mode, $group->is_required ? 'نعم' : 'لا',
                            $group->min_select, $group->max_select, $option->id, $option->name_ar, $option->name_en,
                            (float) $option->price_delta, $option->is_default ? 'نعم' : 'لا', $option->is_available ? 'نعم' : 'لا',
                        ]);
                    }
                }
            }

            $writer->addNewSheetAndMakeItCurrent()->setName('الإضافات')->setSheetView($rtl());
            $writer->addRow(Row::fromValues([
                'رقم الصنف', 'الصنف', 'رقم المجموعة', 'المجموعة بالعربية', 'المجموعة بالإنجليزية',
                'نوع الاختيار', 'نمط السعر', 'إجباري', 'أقل عدد', 'أكثر عدد', 'رقم الخيار',
                'الخيار بالعربية', 'الخيار بالإنجليزية', 'السعر', 'افتراضي', 'متاح',
            ], $header));
            foreach ($groups as $row) {
                $writer->addRow(Row::fromValues($row));
            }

            $writer->close();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.self::filename($restaurant, $stamp).'"',
            'Access-Control-Expose-Headers' => 'Content-Disposition',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @param  list<mixed>  $row
     * @return list<scalar|null>
     */
    private static function cells(array $row): array
    {
        return array_map(fn ($v) => $v instanceof BackedEnum ? $v->value : $v, $row);
    }
}
