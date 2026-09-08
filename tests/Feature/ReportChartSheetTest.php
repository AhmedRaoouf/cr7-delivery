<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/**
 * «إكسل برضه بالرسومات اللي فيها» — the operator's second half.
 *
 * openspout cannot write a chart part, so what the workbook carries is the **series**,
 * on its own worksheet, as a clean contiguous range: label column first, one column per
 * line the screen plots. That is a real, checkable promise and this is where it is
 * checked — an `.xlsx` is a zip, so the sheet's existence, its name, its heading row and
 * its right-to-left flag are all readable without a spreadsheet library.
 *
 * The negative case matters as much: a report that draws no chart must not ship an empty
 * «بيانات الرسم البياني» tab, because a tab with a title and no numbers reads as a bug.
 */
class ReportChartSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $admin;
    }

    /** @param  list<array{0: string, 1: int, 2: float}>  $items  name / quantity / line total */
    private function deliveredOrder(Restaurant $restaurant, string $createdAt, array $items = []): Order
    {
        $customer = $this->makeCustomer();

        $group = OrderGroup::query()->create([
            'group_number' => 'G'.random_int(100000, 999999),
            'idempotency_key' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'payment_method' => 'cash',
            'rollup_status' => 'delivered',
            'subtotal' => 100,
            'delivery_fee' => 20,
            'total' => 120,
        ]);

        $order = Order::query()->create([
            'order_group_id' => $group->id,
            'order_number' => 'O'.random_int(100000, 999999),
            'idempotency_key' => (string) Str::uuid(),
            'group_sequence' => 1,
            'user_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'restaurant_name_snapshot' => $restaurant->name_ar,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'status' => 'delivered',
            'payment_method' => 'cash',
            'delivery_address_text' => 'شارع البحر',
            'delivery_latitude' => 31.3835,
            'delivery_longitude' => 31.4835,
            'subtotal' => 100,
            'commission_amount' => 10,
            'delivery_fee_share' => 20,
            'total' => 120,
        ]);

        foreach ($items as [$name, $quantity, $lineTotal]) {
            $order->items()->create([
                'product_id' => $this->makeProduct($restaurant, $name, $lineTotal / $quantity)->id,
                'product_name_ar_snapshot' => $name,
                'product_name_en_snapshot' => $name,
                'unit_price_snapshot' => $lineTotal / $quantity,
                'quantity' => $quantity,
                'line_total' => $lineTotal,
            ]);
        }

        // `created_at` is what every report groups on, and Eloquent would stamp «now».
        $order->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $order;
    }

    /**
     * Every word the workbook shows, wherever the writer chose to put it.
     *
     * openspout writes **inline strings** by default, so `sharedStrings.xml` is empty
     * and the text is in each `sheetN.xml`. Reading both means the assertions below say
     * «the file contains this», not «the writer is configured that way».
     *
     * @param  array<string, string>  $entries
     */
    private function text(array $entries, ?string $onlySheet = null): string
    {
        $text = '';
        foreach ($entries as $name => $body) {
            if ($onlySheet !== null) {
                if ($name === $onlySheet) {
                    $text .= $body;
                }

                continue;
            }

            if (str_starts_with($name, 'xl/worksheets/') || $name === 'xl/sharedStrings.xml') {
                $text .= $body;
            }
        }

        return $text;
    }

    /** @return array<string, string> the workbook's entries, by name */
    private function unzip(string $body): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $body);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'the response was not a readable zip');

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $entries[$name] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        unlink($path);

        return $entries;
    }

    public function test_the_sales_workbook_carries_the_series_its_two_charts_plot(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $this->withHeader('X-App', 'admin');

        $entries = $this->unzip(
            $this->get('/api/v1/admin/reports/sales/export')->assertOk()->streamedContent()
        );

        $workbook = $entries['xl/workbook.xml'] ?? '';
        $this->assertStringContainsString('name="التقرير"', $workbook);
        $this->assertStringContainsString('name="بيانات الرسم البياني"', $workbook);
        $this->assertArrayHasKey('xl/worksheets/sheet2.xml', $entries);

        // The headings are the axis and the two series `ADM-43` draws, verbatim.
        $strings = $this->text($entries);
        $this->assertStringContainsString('المبيعات وعدد الطلبات عبر الزمن', $strings);
        $this->assertStringContainsString('عدد الطلبات', $strings);
        $this->assertStringContainsString('اختر النطاق بالكامل ثم: إدراج ▸ رسم بياني', $strings);
    }

    public function test_the_customers_workbook_carries_the_retention_cohorts(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $this->withHeader('X-App', 'admin');

        $entries = $this->unzip(
            $this->get('/api/v1/admin/reports/customers/export')->assertOk()->streamedContent()
        );

        $this->assertStringContainsString(
            'name="بيانات الرسم البياني"',
            $entries['xl/workbook.xml'] ?? ''
        );
        $this->assertStringContainsString(
            'شبكة الاحتفاظ — العملاء لكل شهر تسجيل',
            $this->text($entries)
        );
    }

    public function test_a_report_with_no_chart_ships_no_chart_sheet(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $this->withHeader('X-App', 'admin');

        $entries = $this->unzip(
            $this->get('/api/v1/admin/reports/drivers/export')->assertOk()->streamedContent()
        );

        $this->assertArrayNotHasKey('xl/worksheets/sheet2.xml', $entries);
        $this->assertStringNotContainsString(
            'بيانات الرسم البياني',
            $entries['xl/workbook.xml'] ?? ''
        );
    }

    public function test_every_sheet_opens_right_to_left(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $this->withHeader('X-App', 'admin');

        $entries = $this->unzip(
            $this->get('/api/v1/admin/reports/sales/export')->assertOk()->streamedContent()
        );

        // Column A on the right, which is where an Arabic reader looks for it. The old
        // note on `ReportExporter` said openspout could not do this; it can.
        foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml'] as $sheet) {
            $this->assertStringContainsString('rightToLeft="true"', $entries[$sheet] ?? '');
        }
    }

    public function test_a_restaurant_workbook_carries_its_own_two_series(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');

        Sanctum::actingAs($restaurant->owner);
        $this->withHeader('X-App', 'restaurant');

        $entries = $this->unzip(
            $this->get('/api/v1/restaurant/reports/export')->assertOk()->streamedContent()
        );

        $this->assertStringContainsString(
            'name="بيانات الرسم البياني"',
            $entries['xl/workbook.xml'] ?? ''
        );
        $this->assertStringContainsString(
            'المبيعات وعدد الطلبات عبر الفترة',
            $this->text($entries)
        );
    }

    public function test_res_21_can_be_downloaded_at_all(): void
    {
        // «الأصناف الأكثر مبيعًا» was the one screen in either dashboard with rows and
        // columns and no export beside them.
        // Counted off `order_items` on delivered orders in the period — `sold_count` is a
        // lifetime counter no date filter can narrow, and it is not the «الكمية» and
        // «الإيراد» columns `RES-21` renders.
        $restaurant = $this->makeRestaurant('ابن البلد');
        $this->deliveredOrder($restaurant, '2026-01-07 12:00:00', [['كبدة إسكندراني', 7, 455.0]]);

        Sanctum::actingAs($restaurant->owner);
        $this->withHeader('X-App', 'restaurant');

        $response = $this->get('/api/v1/restaurant/reports/top-products/export?filter[date_from]=2026-01-01&filter[date_to]=2026-01-31');
        $response->assertOk();

        $entries = $this->unzip($response->streamedContent());
        $strings = $this->text($entries);
        $this->assertStringContainsString('الأصناف الأكثر مبيعًا', $strings);
        $this->assertStringContainsString('كبدة إسكندراني', $strings);
        // No chart is drawn on `RES-21`, so no chart sheet.
        $this->assertArrayNotHasKey('xl/worksheets/sheet2.xml', $entries);
    }

    public function test_a_restaurant_cannot_download_another_restaurants_products(): void
    {
        $mine = $this->makeRestaurant('ابن البلد');
        $theirs = $this->makeRestaurant('سعد الدمياطي');
        $this->deliveredOrder($mine, '2026-01-07 12:00:00', [['كبدة إسكندراني', 2, 130.0]]);
        $this->deliveredOrder($theirs, '2026-01-07 12:00:00', [['كفتة داوود باشا', 2, 180.0]]);

        Sanctum::actingAs($mine->owner);
        $this->withHeader('X-App', 'restaurant');

        $entries = $this->unzip(
            $this->get('/api/v1/restaurant/reports/top-products/export?filter[date_from]=2026-01-01&filter[date_to]=2026-01-31')
                ->assertOk()->streamedContent()
        );

        $text = $this->text($entries);
        $this->assertStringContainsString('كبدة إسكندراني', $text);
        $this->assertStringNotContainsString('كفتة داوود باشا', $text);
    }

    public function test_the_restaurant_period_filter_is_actually_applied(): void
    {
        // The dashboard sends `filter[date_from]`, doc 08 §2's shape and the one the admin
        // reports already read; this controller read a bare `from`, so the date range on
        // `RES-20` was carried in the URL, printed into the file's own header, and applied
        // to nothing. An export that names a period it did not honour is worse than one
        // with no filter at all.
        $restaurant = $this->makeRestaurant('ابن البلد');

        Sanctum::actingAs($restaurant->owner);
        $this->withHeader('X-App', 'restaurant');

        $entries = $this->unzip(
            $this->get('/api/v1/restaurant/reports/export?filter[date_from]=2026-01-05&filter[date_to]=2026-01-09')
                ->assertOk()->streamedContent()
        );

        $this->assertStringContainsString(
            '2026-01-05 — 2026-01-09',
            $this->text($entries)
        );
    }

    public function test_an_order_outside_the_period_is_not_in_the_file(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $this->deliveredOrder($restaurant, '2026-01-07 12:00:00');

        Sanctum::actingAs($restaurant->owner);
        $this->withHeader('X-App', 'restaurant');

        $wide = $this->unzip(
            $this->get('/api/v1/restaurant/reports/export?filter[date_from]=2026-01-01&filter[date_to]=2026-01-31')
                ->assertOk()->streamedContent()
        );
        $narrow = $this->unzip(
            $this->get('/api/v1/restaurant/reports/export?filter[date_from]=2026-02-01&filter[date_to]=2026-02-28')
                ->assertOk()->streamedContent()
        );

        // The day the order fell on is a row in one file and in neither the other.
        $this->assertStringContainsString('2026-01-07', $this->text($wide));
        $this->assertStringNotContainsString('2026-01-07', $this->text($narrow));
    }
}
