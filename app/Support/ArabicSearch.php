<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Substring search that survives Arabic.
 *
 * `whereFullText()` was here before, and it could not find «كشري» from «كشر». Three
 * reasons, all of them fatal on this catalogue:
 *
 *  1. MySQL's natural-language mode matches **whole words**. «كشر» is not the word
 *     «كشري», so the query answered «لا توجد نتائج» while the dish sat in the menu.
 *  2. InnoDB ignores any token shorter than `innodb_ft_min_token_size` (3 by default),
 *     so «فول» and «رز» were unsearchable outright.
 *  3. There is no Arabic stemmer and no folding: «شاورمه» never matched «شاورما»,
 *     «إفطار» never matched «افطار», and one stray tashkeel in a menu row hid it for good.
 *
 * So both sides are folded to a plain skeleton — hamza forms to bare alif, ة to ه, ى to
 * ي, tatweel and every harakah removed — and matched with LIKE. The needle is split on
 * whitespace and **every** token must appear, which is what makes «فراخ مشوي» narrow the
 * result rather than widen it.
 *
 * This scans rather than using an index. On this catalogue — eighteen restaurants, a few
 * hundred dishes — that is microseconds, and correctness is not negotiable on the one
 * control the customer types into. If the menu ever reaches tens of thousands of rows the
 * answer is a stored normalized column with an index on it, not a return to full-text.
 */
abstract class ArabicSearch
{
    /** Harakat, tatweel and the bidi/joiner marks, all dropped. */
    private const STRIPPED = [
        "\u{0640}", // ـ  tatweel
        "\u{064B}", "\u{064C}", "\u{064D}", "\u{064E}", "\u{064F}", "\u{0650}",
        "\u{0651}", "\u{0652}", "\u{0653}", "\u{0654}", "\u{0655}", "\u{0670}",
        "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}",
        // The embeddings and isolates. Every number inside an Arabic string on this
        // platform is wrapped in `U+202A … U+202C` so it renders the right way round
        // (doc 13 §4) — «مبنى ‪6175‬», «‪145‬ شارع إسماعيل فريد». Nobody types them,
        // so a name that carries one never started with what was typed.
        "\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}",
        "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}",
    ];

    /** Letters that differ only by how carefully someone typed them. */
    private const FOLDED = [
        "\u{0623}" => "\u{0627}", // أ → ا
        "\u{0625}" => "\u{0627}", // إ → ا
        "\u{0622}" => "\u{0627}", // آ → ا
        "\u{0671}" => "\u{0627}", // ٱ → ا
        "\u{0629}" => "\u{0647}", // ة → ه
        "\u{0649}" => "\u{064A}", // ى → ي
        "\u{0624}" => "\u{0648}", // ؤ → و
        "\u{0626}" => "\u{064A}", // ئ → ي
    ];

    /** The longest needle worth honouring; past this it is not a search, it is a paste. */
    public const MAX_LENGTH = 64;

    /** How many whitespace-separated tokens are matched. */
    public const MAX_TOKENS = 5;

    /** The LIKE escape character — see [escapeLike]. */
    private const ESCAPE = '!';

    private const ESCAPE_SQL = "'!'";

    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(self::STRIPPED, '', $value);
        $value = strtr($value, self::FOLDED);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * The tokens a needle is matched by — empty when there is nothing to search for.
     *
     * @return list<string>
     */
    public static function tokens(string $value): array
    {
        $normalized = mb_substr(self::normalize($value), 0, self::MAX_LENGTH);

        if ($normalized === '') {
            return [];
        }

        return array_slice(array_values(array_filter(explode(' ', $normalized))), 0, self::MAX_TOKENS);
    }

    /**
     * The same folding, expressed in SQL, so the stored value is compared on equal terms.
     *
     * Nested REPLACE rather than a collation: the folding this needs (ة/ه, ى/ي) is not
     * what any shipped MySQL collation does, and it has to behave identically on the
     * SQLite the tests run on.
     */
    public static function columnSql(string $column): string
    {
        $sql = "LOWER(COALESCE({$column}, ''))";

        foreach (self::STRIPPED as $char) {
            $sql = "REPLACE({$sql}, ".self::quote($char).", '')";
        }

        foreach (self::FOLDED as $from => $to) {
            $sql = "REPLACE({$sql}, ".self::quote($from).', '.self::quote($to).')';
        }

        return $sql;
    }

    /**
     * `WHERE` every token appears in at least one of [$columns].
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Query\Builder  $query
     * @param  list<string>  $columns
     * @param  list<string>  $tokens
     */
    public static function apply($query, array $columns, array $tokens): void
    {
        foreach ($tokens as $token) {
            $needle = '%'.self::escapeLike($token).'%';

            $query->where(function ($group) use ($columns, $needle): void {
                foreach ($columns as $column) {
                    $group->orWhereRaw(self::columnSql($column).' LIKE ? ESCAPE '.self::ESCAPE_SQL, [$needle]);
                }
            });
        }
    }

    /**
     * The same `WHERE`, against columns that are **already** folded on disk.
     *
     * {@see apply()} wraps every column in a nested `REPLACE` per folded or stripped
     * character — over thirty of them — which no index
     * can be used for and which every row pays on every keystroke. That is the right
     * trade on a catalogue of a few hundred dishes, and the wrong one on the launch
     * city's gazetteer: ‪6 128‬ building points, ‪224‬ streets and ‪32‬ areas, all of them
     * scanned per character typed into the address picker.
     *
     * So those tables carry a stored `*_normalized` column, written by the seeder
     * through {@see normalize()} and indexed. Same folding, same escape, computed once
     * at seed time instead of once per query. The needle still has to be folded here —
     * both sides or neither.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Query\Builder  $query
     * @param  list<string>  $columns  columns already holding {@see normalize()}d text
     * @param  list<string>  $tokens  from {@see tokens()}, so already folded
     */
    public static function applyNormalized($query, array $columns, array $tokens): void
    {
        foreach ($tokens as $token) {
            $needle = '%'.self::escapeLike($token).'%';

            $query->where(function ($group) use ($columns, $needle): void {
                foreach ($columns as $column) {
                    $group->orWhereRaw($column.' LIKE ? ESCAPE '.self::ESCAPE_SQL, [$needle]);
                }
            });
        }
    }

    /**
     * `SELECT`-able rank against an already-folded column: exact 0, prefix 1, else 2.
     *
     * {@see rank()} for a raw column; this is its counterpart for the stored ones. It is
     * what decides which of the matches survive a `LIMIT`: «شارع الكورنيش» starts with
     * the needle «شارع الكورنيش» and the ‪40‬ door numbers along it do not, so the street
     * is kept and the doors are what gets cut.
     */
    public static function rankNormalized(string $column, string $needle): Expression
    {
        $normalized = self::normalize($needle);
        $prefix = self::escapeLike($normalized).'%';

        return DB::raw(
            "CASE WHEN {$column} = ".self::quote($normalized).' THEN 0'
            ." WHEN {$column} LIKE ".self::quote($prefix).' ESCAPE '.self::ESCAPE_SQL.' THEN 1'
            .' ELSE 2 END'
        );
    }

    /**
     * The same three tiers, decided in PHP on a row that is already in memory.
     *
     * Rows merged from four sources cannot be ordered by SQL — only two of them come
     * from this database at all.
     */
    public static function tier(string $haystack, string $needle): int
    {
        $haystack = self::normalize($haystack);
        $needle = self::normalize($needle);

        if ($needle === '') {
            return 2;
        }

        return match (true) {
            $haystack === $needle => 0,
            str_starts_with($haystack, $needle) => 1,
            default => 2,
        };
    }

    /**
     * `ORDER BY` closeness: an exact name first, then a name that starts with the needle,
     * then anything that merely contains it.
     *
     * Without this, «كشري» put «كشري بالعدس الأصفر» above «كشري» itself on nothing more
     * principled than the insertion order of the menu.
     */
    public static function rank(string $column, string $needle): Expression
    {
        $expression = self::columnSql($column);
        $normalized = self::normalize($needle);
        $prefix = self::escapeLike($normalized).'%';

        return DB::raw(
            "CASE WHEN {$expression} = ".self::quote($normalized).' THEN 0'
            ." WHEN {$expression} LIKE ".self::quote($prefix).' ESCAPE '.self::ESCAPE_SQL.' THEN 1'
            .' ELSE 2 END'
        );
    }

    /**
     * `%` and `_` typed by a customer are literals, not wildcards.
     *
     * The escape character is `!`, not the conventional backslash. `ESCAPE '\'` is not a
     * valid MySQL string literal at all — the backslash escapes the closing quote, the
     * string never ends, and the whole statement dies with a syntax error pointing at
     * something else entirely. `!` needs no escaping in any dialect.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace([self::ESCAPE, '%', '_'], [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'], $value);
    }

    private static function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
