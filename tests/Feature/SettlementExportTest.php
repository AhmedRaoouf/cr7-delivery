<?php

namespace Tests\Feature;

use App\Models\Settlement;
use App\Support\SettlementExporter;
use Tests\TestCase;

/**
 * `RES-31` / `ADM-33` — «تطبع إكسل».
 *
 * CSV would have been one line and the wrong answer: Excel on an Arabic Windows
 * install splits a comma-separated file on the system list separator, which is a
 * semicolon there, so every row lands in one column. A real workbook has no
 * separator to disagree about.
 */
class SettlementExportTest extends TestCase
{
    private function settlement(): Settlement
    {
        $settlement = new Settlement([
            'party_type' => 'restaurant',
            'party_id' => 3,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'gross_sales' => 12450.50,
            'commission_total' => 1867.58,
            'refunds_total' => 120.00,
            'delivery_fees_total' => 840.00,
            'platform_fee_total' => 210.00,
            'cash_collected' => 9300.00,
            'net_amount' => 1162.92,
            'status' => 'draft',
        ]);
        $settlement->id = 41;

        return $settlement;
    }

    public function test_the_filename_names_the_settlement_and_its_period(): void
    {
        // An operator who exports twelve months wants twelve distinguishable files,
        // not twelve copies of `settlement.xlsx (3)`.
        $name = SettlementExporter::filename($this->settlement());

        $this->assertSame('settlement-41-2026-08-01-2026-08-31.xlsx', $name);
    }

    public function test_it_is_a_real_workbook_not_a_csv_with_a_new_extension(): void
    {
        $response = SettlementExporter::stream($this->settlement(), 'سندوتش عمر');

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_a_settlement_is_money_and_is_never_cached(): void
    {
        $response = SettlementExporter::stream($this->settlement(), 'سندوتش عمر');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_bytes_are_a_zip_container_which_is_what_xlsx_is(): void
    {
        $response = SettlementExporter::stream($this->settlement(), 'سندوتش عمر');

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();

        // Every .xlsx is a ZIP; `PK` is its magic number. A CSV renamed to .xlsx
        // opens with a warning dialog and this test is what catches that mistake.
        $this->assertSame('PK', substr($body, 0, 2));
        $this->assertGreaterThan(1000, strlen($body));
    }
}
