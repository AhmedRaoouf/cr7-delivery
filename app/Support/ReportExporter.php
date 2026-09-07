<?php

namespace App\Support;

use Generator;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Any report, as a real `.xlsx`.
 *
 * Same reasoning as `SettlementExporter`, and deliberately the same writer: CSV opens
 * in one column on an Arabic Windows install, because Excel there splits on the system
 * list separator — a semicolon — and no amount of quoting changes that. A workbook has
 * no separator to disagree about and no encoding to declare.
 *
 * Streamed row by row from a generator so a year of orders costs the same memory as a
 * day of them. Shared hosting has a 128 MB limit and no worker to offload to.
 *
 * The same limitation applies: openspout cannot mark a sheet right-to-left, so Excel
 * opens it with column A on the left. The text inside every cell reads correctly; only
 * the column order is LTR, and Excel's own «ورقة من اليمين إلى اليسار» fixes it per
 * reader. Switching writers to get that one flag would cost the streaming.
 */
class ReportExporter
{
    /**
     * @param  list<string>  $headers
     * @param  Generator<int, list<scalar|null>>  $rows
     * @param  list<array{0: string, 1: scalar|null}>  $summary  label/value pairs printed above the table
     */
    public static function stream(
        string $filename,
        string $title,
        array $headers,
        Generator $rows,
        array $summary = [],
    ): StreamedResponse {
        return new StreamedResponse(function () use ($title, $headers, $rows, $summary): void {
            $options = new Options;
            // Arabic headings need more than the 8-character default or every one of
            // them opens as «####».
            $options->setColumnWidth(26, ...range(1, max(1, count($headers))));

            $writer = new Writer($options);
            $writer->openToFile('php://output');

            $bold = (new Style)->setFontBold();
            $header = (new Style)->setFontBold()->setCellAlignment(CellAlignment::CENTER);

            $writer->addRow(Row::fromValues([$title], $bold));
            $writer->addRow(Row::fromValues(['أُنشئ في', BusinessTime::now()->format('Y-m-d H:i')]));

            foreach ($summary as [$label, $value]) {
                $writer->addRow(Row::fromValues([$label, $value]));
            }

            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues($headers, $header));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }

            $writer->close();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            // The dashboard reads the name off the response rather than guessing it, and
            // a cross-origin fetch cannot see a header that is not exposed.
            'Access-Control-Expose-Headers' => 'Content-Disposition',
            'Cache-Control' => 'no-store',
        ]);
    }
}
