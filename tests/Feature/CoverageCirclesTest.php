<?php

namespace Tests\Feature;

use App\Support\ZoneLocator;
use Database\Seeders\GamasaZoneGeometrySeeder;
use Database\Seeders\OperatorZonePricingSeeder;
use Database\Seeders\ServiceAreaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Which towns this platform says it delivers to.
 *
 * Operator, 2026-09-12, with two screenshots: the picker drew «المنصورة الجديدة»
 * as a circle over **دمياط الجديدة** and called a pin there serviceable, while a
 * pin on New Mansoura University answered «خارج نطاق التوصيل». One seeded
 * coordinate did both — `ServiceAreaSeeder` had New Mansoura at 31.4436, 31.6553,
 * which is ‪2.2‬ km from Nominatim's centre for New Damietta and ‪19‬ km from the
 * city it is named after.
 *
 * The app resolves coverage on the device, against the circles `GET /areas`
 * publishes, so a wrong circle is wrong on every phone at once and no server
 * check stands between it and the customer. These are the four sentences that
 * matter, asserted at real points rather than at the seeded centres — a test
 * that reads the seeder back would have passed the whole time.
 */
class CoverageCirclesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            ServiceAreaSeeder::class,
            OperatorZonePricingSeeder::class,
            GamasaZoneGeometrySeeder::class,
        ]);
    }

    /**
     * @return array<string, array{0: float, 1: float}>
     */
    public static function served(): array
    {
        return [
            // OSM way/1305151351 «New Mansoura University - جامعة المنصورة الجديدة».
            'جامعة المنصورة الجديدة' => [31.4610, 31.4571],
            // OSM amenity=university, both inside the launch corridor.
            'جامعة المنصورة الأهلية' => [31.4487, 31.4809],
            'جامعة الدلتا' => [31.4426, 31.4939],
            // OSM place=city «جمصة».
            'وسط جمصة' => [31.4388, 31.5442],
            // OSM place=neighbourhood «منطقة ايزيس», a priced area.
            'إيزيس' => [31.4411, 31.5230],
        ];
    }

    /**
     * @return array<string, array{0: float, 1: float}>
     */
    public static function notServed(): array
    {
        return [
            // Nominatim place=city «دمياط الجديدة». The whole point of this file.
            'دمياط الجديدة' => [31.4365, 31.6781],
            // Damietta port, further east again.
            'ميناء دمياط' => [31.4650, 31.7550],
            // Central Mansoura, ‪45‬ km inland — the forward-geocode trap.
            'المنصورة' => [31.0409, 31.3785],
        ];
    }

    #[DataProvider('served')]
    public function test_a_point_we_deliver_to_falls_in_a_circle(float $lat, float $lng): void
    {
        $this->assertNotNull(
            ZoneLocator::forPoint($lat, $lng),
            'no coverage circle contains this point',
        );
    }

    #[DataProvider('notServed')]
    public function test_a_point_we_do_not_deliver_to_falls_in_none(float $lat, float $lng): void
    {
        $this->assertNull(
            ZoneLocator::forPoint($lat, $lng),
            'a coverage circle reaches a town this platform does not serve',
        );

        // And the server must not disagree with the device. `POST /geo/resolve`
        // falls back to the city circle when no zone matches, so a city radius
        // wider than its zones would call the same pin serviceable.
        $this->assertNull(
            ZoneLocator::cityForPoint($lat, $lng),
            'a city circle reaches a town this platform does not serve',
        );
    }
}
