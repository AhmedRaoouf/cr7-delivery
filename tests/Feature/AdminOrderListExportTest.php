<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\Driver;
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
 * `ADM-04` — the filters, and the same table as a workbook.
 *
 * Operator, 2026-09-10: «عايزين في الطلبات اللي في الادمن يطبعها برده اكسل و pdf واعمل
 * فلاتر علي كل حاجه وبالاخص المطاعم وضيف عمود وقم الطلب في المطعم».
 *
 * Three things are checked, and the third is the one that would silently rot:
 *
 * 1. The list can be narrowed to one place, one captain, one payment method.
 * 2. `restaurant_order_ref` — the number the kitchen writes on its own ticket — is on
 *    the row and is searchable, because the phone call an operator gets says «الطلب رقم
 *    104 عندنا», never the platform's own `CR7-…`.
 * 3. **The download is the table.** The list and the export share `AdminOrderFilters`;
 *    if one ever grows a clause the other has not, an operator downloads a file that
 *    disagrees with the screen they are looking at and has no way to tell.
 *
 * PDF is not tested here because there is no PDF endpoint: `PrintButton` prints the page
 * the browser has already laid out, against `print.css`. See `ReportExportController`'s
 * note on why an Arabic PDF is not written server-side.
 */
class AdminOrderListExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
        $this->withHeader('X-App', 'admin');
    }

    private function actingAsSuperAdmin(): User
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function makeOrder(Restaurant $restaurant, array $overrides = []): Order
    {
        $customer = $this->makeCustomer();

        $group = OrderGroup::query()->create([
            'group_number' => 'G'.random_int(100000, 999999),
            'idempotency_key' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'payment_method' => $overrides['payment_method'] ?? 'cash',
            'rollup_status' => 'delivered',
            'subtotal' => 100,
            'delivery_fee' => 20,
            'total' => 120,
        ]);

        // `$overrides` first: PHP's `+` keeps the **left** operand on a duplicate key,
        // so the defaults below have to be the right-hand side or nothing can be overridden.
        return Order::query()->create($overrides + [
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
            $entries[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        unlink($path);

        return $entries;
    }

    /** openspout writes inline strings, so the words are in the sheet, not a shared table. */
    private function sheetText(string $body): string
    {
        $text = '';
        foreach ($this->unzip($body) as $name => $entry) {
            if (str_starts_with($name, 'xl/worksheets/')) {
                $text .= $entry;
            }
        }

        return $text;
    }

    public function test_the_list_narrows_to_one_place(): void
    {
        $this->actingAsSuperAdmin();
        $box = $this->makeRestaurant('بوكس ع السريع');
        $omar = $this->makeRestaurant('سندوتش عمر');

        $mine = $this->makeOrder($box);
        $this->makeOrder($omar);

        $rows = $this->getJson("/api/v1/admin/orders?filter[restaurant_id]={$box->id}")
            ->assertOk()->json('data');

        $this->assertSame([$mine->id], array_column($rows, 'id'));
    }

    public function test_the_list_narrows_to_one_captain_and_one_payment_method(): void
    {
        $this->actingAsSuperAdmin();
        $place = $this->makeRestaurant('ابن البلد');

        $captainUser = User::factory()->create(['type' => 'driver']);
        $captain = Driver::query()->create([
            'user_id' => $captainUser->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'fixed',
            'earning_value' => 15,
            'is_available' => true,
        ]);

        $his = $this->makeOrder($place, ['driver_id' => $captain->id]);
        $this->makeOrder($place);
        $wallet = $this->makeOrder($place, ['payment_method' => 'vodafone_cash']);

        $this->assertSame(
            [$his->id],
            array_column($this->getJson("/api/v1/admin/orders?filter[driver_id]={$captain->id}")->assertOk()->json('data'), 'id'),
        );

        $this->assertSame(
            [$wallet->id],
            array_column($this->getJson('/api/v1/admin/orders?filter[payment_method]=vodafone_cash')->assertOk()->json('data'), 'id'),
        );
    }

    /**
     * The number the restaurant reads back over the phone.
     *
     * It has always been a column on `orders`; it was on no screen and in no search, so
     * the one identifier both sides of a phone call can see was the one nobody could
     * look up.
     */
    public function test_the_restaurants_own_order_number_is_on_the_row_and_is_searchable(): void
    {
        $this->actingAsSuperAdmin();
        $place = $this->makeRestaurant('كنج بروست');

        $theirs = $this->makeOrder($place, ['restaurant_order_ref' => '104']);
        $this->makeOrder($place, ['restaurant_order_ref' => '221']);

        $rows = $this->getJson('/api/v1/admin/orders')->assertOk()->json('data');
        $this->assertContains('104', array_column($rows, 'restaurant_order_ref'));

        $found = $this->getJson('/api/v1/admin/orders?filter[search]=104')->assertOk()->json('data');
        $this->assertSame([$theirs->id], array_column($found, 'id'));
    }

    public function test_the_workbook_carries_the_row_the_screen_shows_plus_the_kitchens_number(): void
    {
        $this->actingAsSuperAdmin();
        $place = $this->makeRestaurant('صواني الدمياطي');
        $this->makeOrder($place, ['restaurant_order_ref' => '512']);

        $body = $this->get('/api/v1/admin/orders/export')->assertOk()->streamedContent();
        $text = $this->sheetText($body);

        $this->assertStringContainsString('رقم الطلب في المطعم', $text);
        $this->assertStringContainsString('512', $text);
        $this->assertStringContainsString('صواني الدمياطي', $text);
        // The four columns the screen has no room for.
        $this->assertStringContainsString('طريقة الدفع', $text);
        $this->assertStringContainsString('العمولة', $text);
    }

    /**
     * The promise that keeps the file honest: the download is the table.
     */
    public function test_the_download_honours_the_same_filters_as_the_list(): void
    {
        $this->actingAsSuperAdmin();
        $box = $this->makeRestaurant('بوكس ع السريع');
        $omar = $this->makeRestaurant('سندوتش عمر');

        $this->makeOrder($box, ['restaurant_order_ref' => '777']);
        $this->makeOrder($omar, ['restaurant_order_ref' => '888']);

        $text = $this->sheetText(
            $this->get("/api/v1/admin/orders/export?filter[restaurant_id]={$box->id}")->assertOk()->streamedContent()
        );

        $this->assertStringContainsString('777', $text);
        $this->assertStringNotContainsString('888', $text);
        // The filter is restated on the sheet, so a file found in a downloads folder
        // three weeks later still says what it is a list of.
        $this->assertStringContainsString('بوكس ع السريع', $text);
    }

    public function test_an_admin_who_may_not_read_orders_may_not_download_them(): void
    {
        $viewer = User::factory()->create(['type' => 'admin']);
        $viewer->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($viewer);

        $this->get('/api/v1/admin/orders/export')->assertForbidden();
    }

    /** The route is declared before `/orders/{order}`, or the binder eats the word. */
    public function test_the_export_path_is_not_read_as_an_order_id(): void
    {
        $this->actingAsSuperAdmin();

        $this->get('/api/v1/admin/orders/export')->assertOk();
    }
}
