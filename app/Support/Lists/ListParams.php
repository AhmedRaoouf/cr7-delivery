<?php

namespace App\Support\Lists;

use App\Support\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Batch 68 — one reader for every list endpoint's query string (ADMIN-LISTS.md §0).
 *
 * Before this each controller read its own filters its own way: `$request->string(
 * 'filter.status')` here, `filterString()` there, `$request->date()` somewhere else.
 * The result was the same filter meaning three things — a status that took one value on
 * one screen and silently ignored a second one on the next, a date range read in UTC on
 * the order board and in Cairo on the dashboard beside it, a malformed date that
 * answered 500 on one list and nothing on another.
 *
 * So the rules live here, once:
 *
 *  - `filter[key]` wins, the bare `key` is the fallback (the consoles and the customer
 *    catalogue send it that way, and removing it would break a phone to fix a screen);
 *  - an enum takes one value, a comma list or an array; `all` means no filter; an
 *    unknown value is a 422, never a silently empty page;
 *  - ids and booleans that are not ids and booleans are a 422;
 *  - dates are Cairo business days, inclusive, and a reversed range is a 422;
 *  - `per_page` is 1…100;
 *  - an unknown `sort` falls back to the default — a sort is a preference, not a query.
 *
 * The 422 is the house envelope (`validation_failed`, `errors["filter.<key>"]`), rendered
 * by `bootstrap/app.php` like every other validation failure.
 */
final class ListParams
{
    public const MAX_PER_PAGE = 100;

    /** The longest search term honoured — past this it is a paste, not a query. */
    public const MAX_SEARCH = 64;

    private const TRUE = ['1', 'true', 'yes', 'on'];

    private const FALSE = ['0', 'false', 'no', 'off'];

    public function __construct(public readonly Request $request) {}

    public static function of(Request $request): self
    {
        return new self($request);
    }

    /**
     * The raw value — `filter[key]` first, then the bare `key`. Null when neither is
     * present or both are blank.
     *
     * @return string|array<int|string, mixed>|null
     */
    public function raw(string $key): string|array|null
    {
        foreach (['filter.'.$key, $key] as $path) {
            $value = $this->request->input($path);

            if (is_array($value)) {
                $value = array_values(array_filter($value, fn ($v) => is_scalar($v) && trim((string) $v) !== ''));

                if ($value !== []) {
                    return $value;
                }

                continue;
            }

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    public function has(string $key): bool
    {
        return $this->raw($key) !== null;
    }

    /** '' when absent — a single string, arrays joined by commas. */
    public function string(string $key): string
    {
        $value = $this->raw($key);

        return is_array($value) ? implode(',', array_map('strval', $value)) : (string) ($value ?? '');
    }

    /**
     * A comma list or an array, trimmed, blanks dropped, duplicates removed.
     *
     * @return list<string>
     */
    public function list(string $key): array
    {
        $value = $this->raw($key);

        if ($value === null) {
            return [];
        }

        $parts = is_array($value) ? $value : explode(',', $value);

        return array_values(array_unique(array_filter(
            array_map(fn ($part) => trim((string) $part), $parts),
            fn (string $part) => $part !== '',
        )));
    }

    /**
     * Allowed values only. `all` anywhere in the list means no filter at all — it is what
     * a «الكل» chip sends. An alias maps a friendly spelling onto the stored one
     * (`direct` → `restaurant_direct`).
     *
     * @param  list<string>  $allowed
     * @param  array<string, string>  $aliases
     * @return list<string>
     */
    public function enum(string $key, array $allowed, array $aliases = []): array
    {
        $values = $this->list($key);

        if ($values === [] || in_array('all', $values, true)) {
            return [];
        }

        $resolved = [];

        foreach ($values as $value) {
            $value = $aliases[$value] ?? $value;

            if (! in_array($value, $allowed, true)) {
                $this->fail($key, __('قيمة غير معروفة: :value', ['value' => mb_substr($value, 0, 40)]));
            }

            $resolved[] = $value;
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Positive integers only.
     *
     * @param  list<string>  $words  non-numeric words the caller accepts as-is (`none`, `me`)
     * @return list<int|string>
     */
    public function ids(string $key, array $words = []): array
    {
        $ids = [];

        foreach ($this->list($key) as $value) {
            if (in_array($value, $words, true)) {
                $ids[] = $value;

                continue;
            }

            if (! ctype_digit($value) || (int) $value < 1) {
                $this->fail($key, __('يجب أن يكون معرّفًا رقميًا'));
            }

            $ids[] = (int) $value;
        }

        return array_values(array_unique($ids, SORT_REGULAR));
    }

    /** One id, or null. A list is refused rather than silently cut to its first value. */
    public function id(string $key): ?int
    {
        $ids = $this->ids($key);

        if (count($ids) > 1) {
            $this->fail($key, __('يجب أن يكون معرّفًا رقميًا'));
        }

        return $ids === [] ? null : (int) $ids[0];
    }

    /** Null when absent; `?filter[overdue]=0` is a real answer and stays false. */
    public function bool(string $key): ?bool
    {
        $value = $this->raw($key);

        if ($value === null) {
            return null;
        }

        $value = strtolower(is_array($value) ? (string) end($value) : $value);

        return match (true) {
            in_array($value, self::TRUE, true) => true,
            in_array($value, self::FALSE, true) => false,
            default => $this->fail($key, __('يجب أن تكون القيمة true أو false')),
        };
    }

    /**
     * The Cairo business-day range `[from, to]`, each in UTC and ready for a `where`.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public function dates(string $fromKey = 'date_from', string $toKey = 'date_to'): array
    {
        $from = $this->date($fromKey, endOfDay: false);
        $to = $this->date($toKey, endOfDay: true);

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            $this->fail($fromKey, __('تاريخ البداية بعد تاريخ النهاية'));
        }

        return [$from, $to];
    }

    public function date(string $key, bool $endOfDay = false): ?Carbon
    {
        $value = $this->string($key);

        if ($value === '') {
            return null;
        }

        // A date, or an ISO date-time. Anything else — «أمس», «18/09/2026», a stray
        // comma — is a 422, not a parse that happens to land on some other day.
        if (preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/', $value) !== 1) {
            $this->fail($key, __('تاريخ غير صالح، الصيغة YYYY-MM-DD'));
        }

        try {
            $parsed = Carbon::parse($value);
        } catch (Throwable) {
            $this->fail($key, __('تاريخ غير صالح، الصيغة YYYY-MM-DD'));
        }

        // `Carbon::parse` rolls 2026-02-31 over into March; a date that does not exist
        // is not a date.
        if ($parsed->format('Y-m-d') !== substr($value, 0, 10)) {
            $this->fail($key, __('تاريخ غير صالح، الصيغة YYYY-MM-DD'));
        }

        return BusinessTime::fromInput($parsed, endOfDay: $endOfDay);
    }

    /**
     * The search box: `filter[search]`, `search`, `filter[q]`, `q` — the first non-blank.
     * Capped at {@see MAX_SEARCH} characters.
     */
    public function search(): string
    {
        foreach (['search', 'q'] as $key) {
            $value = $this->string($key);

            if ($value !== '') {
                return mb_substr($value, 0, self::MAX_SEARCH);
            }
        }

        return '';
    }

    /** 1…100, the endpoint's default when absent or not a number. */
    public function perPage(int $default = 20): int
    {
        $max = self::maxPerPage();
        $raw = $this->request->input('per_page');

        if (! is_numeric($raw)) {
            return min($default, $max);
        }

        return max(1, min((int) $raw, $max));
    }

    /** `security.max_per_page` — the same ceiling `ForceJsonResponse` clamps to. */
    public static function maxPerPage(): int
    {
        return max(1, (int) config('security.max_per_page', self::MAX_PER_PAGE));
    }

    /**
     * `sort=field` / `sort=-field`, whitelisted. Unknown → the default, silently.
     *
     * @param  list<string>  $allowed  public field names
     * @param  string  $default  e.g. `-created_at`
     */
    public function sort(array $allowed, string $default): SortOrder
    {
        $raw = trim((string) $this->request->input('sort', ''));

        if ($raw !== '') {
            $field = ltrim($raw, '-');

            if (in_array($field, $allowed, true)) {
                return new SortOrder($field, str_starts_with($raw, '-'));
            }
        }

        return new SortOrder(ltrim($default, '-'), str_starts_with($default, '-'));
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages(['filter.'.$key => [$message]]);
    }
}
