<?php

namespace App\Support\Media;

use Illuminate\Support\Facades\DB;

/**
 * Who stores a given picture URL today (doc 71 §6).
 *
 * The library and the fields that use it are joined **only by the URL string**, so this is
 * the one place that can say «this file is still in use». Two callers depend on it:
 *
 *  - {@see \App\Support\FileUploader::deletePublic()} — a file two rows share (a library
 *    picture chosen for two products, an own picture reused) must survive the first row
 *    replacing it;
 *  - «delete the physical asset» — refused while anything still shows the picture.
 *
 * Raw `DB::table` on purpose: a soft-deleted product still stores its URL, and «deleted»
 * is not «no longer references».
 */
final class MediaReferences
{
    /** @var list<array{0: string, 1: string, 2: bool}> table, column, isJsonList */
    private const COLUMNS = [
        ['restaurants', 'logo', false],
        ['restaurants', 'cover', false],
        ['products', 'image', false],
        ['products', 'gallery', true],
        ['menu_categories', 'image', false],
        ['banners', 'image_ar', false],
        ['banners', 'image_en', false],
        ['store_categories', 'image', false],
        ['store_categories', 'icon', false],
        ['cuisines', 'image', false],
        ['cuisines', 'icon', false],
        ['home_popups', 'image', false],
    ];

    /** How many stored fields currently hold this exact URL. */
    public static function count(string $url): int
    {
        if ($url === '') {
            return 0;
        }

        $total = 0;

        foreach (self::COLUMNS as [$table, $column, $isList]) {
            $query = DB::table($table);

            $total += $isList
                ? $query->whereJsonContains($column, $url)->count()
                : $query->where($column, $url)->count();
        }

        return $total;
    }

    /**
     * Every stored picture URL and how many fields hold it — one grouped query per column,
     * for the list screens that show «used in N places» and filter «unused».
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $counts = [];

        foreach (self::COLUMNS as [$table, $column, $isList]) {
            if ($isList) {
                foreach (DB::table($table)->whereNotNull($column)->pluck($column) as $raw) {
                    foreach ((array) json_decode((string) $raw, true) as $url) {
                        if (is_string($url) && $url !== '') {
                            $counts[$url] = ($counts[$url] ?? 0) + 1;
                        }
                    }
                }

                continue;
            }

            foreach (DB::table($table)->whereNotNull($column)->where($column, '!=', '')
                ->groupBy($column)->selectRaw("{$column} as url, count(*) as n")->get() as $row) {
                $counts[$row->url] = ($counts[$row->url] ?? 0) + (int) $row->n;
            }
        }

        return $counts;
    }

    /** @return array<string, int> «table.column» → rows, only where it is above zero */
    public static function breakdown(string $url): array
    {
        $out = [];

        foreach (self::COLUMNS as [$table, $column, $isList]) {
            $query = DB::table($table);
            $n = $isList
                ? $query->whereJsonContains($column, $url)->count()
                : $query->where($column, $url)->count();

            if ($n > 0) {
                $out["{$table}.{$column}"] = $n;
            }
        }

        return $out;
    }
}
