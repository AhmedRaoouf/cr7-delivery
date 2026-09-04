<?php

namespace App\Support;

use App\Models\Settlement;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A settlement as a real `.xlsx`.
 *
 * CSV would have been one line of code and the wrong answer: Excel on an Arabic
 * Windows install reads a comma-separated file with the system list separator, which
 * is a semicolon there, so every row lands in one column. A real workbook has no
 * separator to disagree about and no encoding to declare.
 *
 * Written with `openspout` rather than PhpSpreadsheet because it streams — a
 * settlement covering a busy month is thousands of order lines, and PhpSpreadsheet
 * holds the whole sheet in memory before writing a byte. Shared hosting has a
 * 128 MB limit.
 *
 * One limitation worth stating rather than hiding: openspout cannot mark the sheet
 * right-to-left, so Excel opens it with column A on the left. The labels are Arabic
 * and read correctly inside their cells; only the column order is LTR. Excel's own
 * «ورقة من اليمين إلى اليسار» toggle fixes it per reader, and switching writers to
 * get that one flag would cost the streaming this needs.
 */
class SettlementExporter
{
    public static function filename(Settlement $settlement): string
    {
        return sprintf(
            'settlement-%d-%s-%s.xlsx',
            $settlement->id,
            $settlement->period_start?->format('Y-m-d') ?? 'start',
            $settlement->period_end?->format('Y-m-d') ?? 'end',
        );
    }

    public static function stream(Settlement $settlement, string $partyName): StreamedResponse
    {
        $filename = self::filename($settlement);

        return new StreamedResponse(function () use ($settlement, $partyName): void {
            $options = new Options;
            // Arabic labels and money need more than the 8-character default, or
            // every heading opens as «####».
            $options->setColumnWidth(24, 1, 2, 3, 4, 5, 6, 7);

            $writer = new Writer($options);
            $writer->openToFile('php://output');

            $header = (new Style)->setFontBold()->setCellAlignment(CellAlignment::CENTER);

            foreach (self::summaryRows($settlement, $partyName) as $row) {
                $writer->addRow(Row::fromValues($row));
            }

            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues([
                'رقم الطلب', 'النوع', 'إجمالي الطعام', 'العمولة',
                'رسوم التوصيل', 'رسوم الخدمة', 'الصافي',
            ], $header));

            // `cursor()` rather than `get()`: one order line at a time, so a month
            // with ten thousand orders costs the same memory as one with ten.
            foreach ($settlement->items()->with('order:id,order_number')->cursor() as $item) {
                $writer->addRow(Row::fromValues([
                    $item->order?->order_number ?? (string) $item->order_id,
                    $item->type instanceof \BackedEnum ? $item->type->value : (string) $item->type,
                    (float) $item->gross,
                    (float) $item->commission,
                    (float) $item->delivery_fee,
                    (float) $item->platform_fee,
                    (float) $item->net,
                ]));
            }

            $writer->close();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            // A settlement is money and is regenerated when it is recalculated.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @return list<list<string|float>> */
    private static function summaryRows(Settlement $settlement, string $partyName): array
    {
        return [
            ['تسوية رقم', (string) $settlement->id],
            ['الطرف', $partyName],
            ['النوع', $settlement->party_type instanceof \BackedEnum ? $settlement->party_type->value : (string) $settlement->party_type],
            ['من', $settlement->period_start?->format('Y-m-d') ?? ''],
            ['إلى', $settlement->period_end?->format('Y-m-d') ?? ''],
            ['الحالة', $settlement->status instanceof \BackedEnum ? $settlement->status->value : (string) $settlement->status],
            [],
            ['إجمالي المبيعات', (float) $settlement->gross_sales],
            ['العمولة', (float) $settlement->commission_total],
            ['المستردات', (float) $settlement->refunds_total],
            ['رسوم التوصيل', (float) $settlement->delivery_fees_total],
            // D-63 — reported, never paid out. It sits outside `net_amount` for both
            // party types because neither the restaurant nor the captain earned it.
            ['رسوم الخدمة (لا تُدفع)', (float) $settlement->platform_fee_total],
            ['نقدية محصّلة', (float) $settlement->cash_collected],
            ['الصافي المستحق', (float) $settlement->net_amount],
        ];
    }
}
