<?php

namespace App\Support;

use Generator;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
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
 * ---
 *
 * **The charts.** The operator asked for «إكسل برضه بالرسومات اللي فيها» — the same
 * charts the report screen draws, inside the workbook. openspout **cannot write a
 * chart**: it is a streaming writer that emits `sheet1.xml` and nothing else, and a
 * chart is a separate `chart1.xml` part plus a drawing relationship the writer has no
 * concept of. There is no flag for it and no extension point; the alternative is
 * `phpoffice/phpspreadsheet`, which does write charts, builds the whole workbook in
 * memory, and is not a dependency of this project — and adding one would mean a
 * `composer update` on a shared host that must never see one.
 *
 * So this does the honest second-best and says so on the sheet itself: a second
 * worksheet, «بيانات الرسم البياني», holding **exactly the series the screen plots** —
 * one label column and one column per line — as a clean contiguous range. Selecting it
 * and pressing «إدراج ▸ رسم بياني» is two clicks, and unlike a baked-in picture the
 * result is live: the operator can change the type, the range, and the colours.
 *
 * A caller with no chart on screen passes nothing and the workbook stays one sheet.
 *
 * ---
 *
 * **RTL.** An earlier version of this note said openspout could not mark a sheet
 * right-to-left. It can — `SheetView::setRightToLeft()`, written into `<sheetViews>`
 * when the workbook is closed — so every sheet here opens with column A on the right,
 * which is where an Arabic reader looks for the first column.
 */
class ReportExporter
{
    /**
     * @param  list<string>  $headers
     * @param  Generator<int, list<scalar|null>>  $rows
     * @param  list<array{0: string, 1: scalar|null}>  $summary  label/value pairs printed above the table
     * @param  null|array{title: string, headers: list<string>, rows: iterable<list<scalar|null>>}  $chart
     *                                                                                                      The series the on-screen chart plots, written to a second worksheet
     */
    public static function stream(
        string $filename,
        string $title,
        array $headers,
        Generator $rows,
        array $summary = [],
        ?array $chart = null,
    ): StreamedResponse {
        return new StreamedResponse(function () use ($title, $headers, $rows, $summary, $chart): void {
            $options = new Options;
            // Arabic headings need more than the 8-character default or every one of
            // them opens as «####».
            $columnCount = max(1, count($headers), count($chart['headers'] ?? []));
            $options->setColumnWidth(26, ...range(1, $columnCount));

            $writer = new Writer($options);
            $writer->openToFile('php://output');

            $bold = (new Style)->setFontBold();
            $header = (new Style)->setFontBold()->setCellAlignment(CellAlignment::CENTER);

            $writer->getCurrentSheet()
                ->setName('التقرير')
                ->setSheetView((new SheetView)->setRightToLeft(true));

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

            if ($chart !== null) {
                $writer->addNewSheetAndMakeItCurrent()
                    ->setName('بيانات الرسم البياني')
                    ->setSheetView((new SheetView)->setRightToLeft(true));

                $writer->addRow(Row::fromValues([$chart['title']], $bold));
                // Said on the sheet rather than only in a changelog: the operator opening
                // this file is the person who has to press the two buttons.
                $writer->addRow(Row::fromValues(['اختر النطاق بالكامل ثم: إدراج ▸ رسم بياني']));
                $writer->addRow(Row::fromValues([]));
                $writer->addRow(Row::fromValues($chart['headers'], $header));

                foreach ($chart['rows'] as $row) {
                    $writer->addRow(Row::fromValues($row));
                }
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
