<?php

namespace App\Support\Lists;

use App\Support\ArabicSearch;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;

/**
 * The search box of every list (ADMIN-LISTS.md §0.3): one term, many haystacks, OR-ed —
 * and the whole OR wrapped in one `where (…)`, so it is AND-ed with the admin's scope and
 * every other filter. An `orWhere` that escaped its group would be a scope bypass, which
 * is why nothing but this class builds a list's search clause.
 *
 * The spec names the columns by kind:
 *
 *  - `names`  — Arabic-folded on both sides ({@see ArabicSearch}); every word must appear
 *               in at least one of them;
 *  - `phones` — the digits typed, in any spelling of an Egyptian number, matched anywhere;
 *  - `emails`, `refs` — contains (the column collations are case-insensitive);
 *  - `id`     — true (the model's key) or a column, matched exactly when the term is a number;
 *  - `relations` — `['user' => [...spec]]`, searched through `whereHas`.
 *
 * @phpstan-type Spec array{names?: list<string>, phones?: list<string>, emails?: list<string>, refs?: list<string>, id?: bool|string, relations?: array<string, array<string, mixed>>}
 */
final class ListSearch
{
    private const ARABIC_INDIC = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];

    /** Fewer digits than this match half the phone book; they still match an id. */
    private const MIN_PHONE_DIGITS = 3;

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $spec
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, string $term, array $spec): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $group) use ($term, $spec): void {
            self::clauses($group, $term, $spec + ['id' => true]);
        });
    }

    /**
     * The digit strings a typed phone can be found by, or [] when the term is not a phone.
     *
     * @return list<string>
     */
    public static function phoneNeedles(string $term): array
    {
        $converted = strtr($term, self::ARABIC_INDIC);

        // A letter means a name, not a number.
        if (preg_match('/[^\d\s+\-().]/u', $converted) === 1) {
            return [];
        }

        $digits = preg_replace('/\D+/', '', $converted) ?? '';

        if (strlen($digits) < self::MIN_PHONE_DIGITS) {
            return [];
        }

        $needles = [$digits, PhoneNumber::normalise($digits)];

        // A country code typed before a partial number: `+20 101` is `101…`.
        if (str_starts_with($digits, '0020')) {
            $needles[] = substr($digits, 4);
        } elseif (str_starts_with($digits, '20')) {
            $needles[] = substr($digits, 2);
        }

        return array_values(array_unique(array_filter(
            $needles,
            fn (string $needle) => strlen($needle) >= self::MIN_PHONE_DIGITS,
        )));
    }

    /**
     * @param  Builder<*>  $query
     * @param  array<string, mixed>  $spec
     */
    private static function clauses(Builder $query, string $term, array $spec): void
    {
        $table = $query->getModel()->getTable();
        $qualify = fn (string $column) => str_contains($column, '.') ? $column : $table.'.'.$column;

        $tokens = ArabicSearch::tokens($term);
        $names = array_map($qualify, $spec['names'] ?? []);

        if ($names !== [] && $tokens !== []) {
            $query->orWhere(fn (Builder $n) => ArabicSearch::apply($n, $names, $tokens));
        }

        $contains = '%'.ArabicSearch::escapeLike($term).'%';

        foreach ([...($spec['refs'] ?? []), ...($spec['emails'] ?? [])] as $column) {
            $query->orWhereRaw($qualify($column)." LIKE ? ESCAPE '!'", [$contains]);
        }

        $needles = self::phoneNeedles($term);

        foreach ($spec['phones'] ?? [] as $column) {
            foreach ($needles as $needle) {
                $query->orWhere($qualify($column), 'like', '%'.$needle.'%');
            }
        }

        $digits = strtr($term, self::ARABIC_INDIC);

        if (($spec['id'] ?? false) !== false && ctype_digit($digits) && strlen($digits) <= 18) {
            $column = $spec['id'] === true ? $query->getModel()->getQualifiedKeyName() : $qualify($spec['id']);
            $query->orWhere($column, (int) $digits);
        }

        foreach ($spec['relations'] ?? [] as $relation => $sub) {
            $query->orWhereHas($relation, function (Builder $related) use ($term, $sub): void {
                $related->where(fn (Builder $inner) => self::clauses($inner, $term, $sub + ['id' => false]));
            });
        }
    }
}
