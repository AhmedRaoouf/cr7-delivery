<?php

namespace App\Support\Promo;

use App\Models\PromoCode;
use App\Models\Restaurant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * doc 96 — the code and the title an operator does not have to invent.
 *
 * «عايز يعمل كود معين لمكان معين وهو اللي يختار الكود أو يتعمل على حسب المكان واسمه»
 * — operator, 2026-10-27. A typed code always wins; an empty one is made from the
 * first place's English name and the amount: تكة + ‪20‬% → `TAKKA20`, free delivery
 * → `TAKKAFREE`, no place → `CR20`. The title reads the same way in both languages.
 */
final class PromoCodeNamer
{
    private const MAX_STEM = 12;

    /**
     * @param  list<int>  $restaurantIds
     * @return array{code: string, title_ar: string, title_en: string}
     */
    public static function suggest(array $restaurantIds, string $type, float $value, ?int $ignoreId = null): array
    {
        $places = self::places($restaurantIds);

        return [
            'code' => self::uniqueCode(self::stem($places).self::amountTag($type, $value), $ignoreId),
            'title_ar' => self::titleAr($places, $type, $value),
            'title_en' => self::titleEn($places, $type, $value),
        ];
    }

    /** @return Collection<int, Restaurant> in the order the operator picked them */
    private static function places(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $rows = Restaurant::query()->whereIn('id', $ids)->get(['id', 'name_ar', 'name_en'])->keyBy('id');

        return collect($ids)->map(fn (int $id) => $rows->get($id))->filter()->values();
    }

    private static function stem(Collection $places): string
    {
        $first = $places->first();
        $latin = $first === null ? '' : strtoupper(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii((string) $first->name_en)) ?? '');

        // A place with no English name, or one whose name is all digits, gets the brand's.
        if ($latin === '' || ctype_digit($latin)) {
            return 'CR';
        }

        return substr($latin, 0, self::MAX_STEM);
    }

    private static function amountTag(string $type, float $value): string
    {
        return $type === 'free_delivery' ? 'FREE' : (string) (int) round($value);
    }

    private static function uniqueCode(string $base, ?int $ignoreId): string
    {
        $taken = fn (string $code) => PromoCode::withTrashed()
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if (! $taken($base)) {
            return $base;
        }

        for ($n = 2; $n < 100; $n++) {
            if (! $taken($base.$n)) {
                return $base.$n;
            }
        }

        return $base.strtoupper(Str::random(4));
    }

    private static function titleAr(Collection $places, string $type, float $value): string
    {
        $amount = self::number($value);
        $head = match ($type) {
            'percent' => "خصم {$amount}%",
            'fixed' => "خصم {$amount} ج.م",
            default => 'توصيل مجاني',
        };

        if ($places->isEmpty()) {
            return $type === 'free_delivery' ? $head.' من كل الأماكن' : $head.' على كل الأماكن';
        }

        $names = $places->count() > 2
            ? $places->count().' أماكن'
            : $places->pluck('name_ar')->join(' و');

        return $head.($type === 'free_delivery' ? ' من ' : ' على ').$names;
    }

    private static function titleEn(Collection $places, string $type, float $value): string
    {
        $amount = self::number($value);
        $head = match ($type) {
            'percent' => "{$amount}% off",
            'fixed' => "EGP {$amount} off",
            default => 'Free delivery',
        };

        if ($places->isEmpty()) {
            return $head.($type === 'free_delivery' ? ' from every place' : ' at every place');
        }

        $names = $places->count() > 2
            ? $places->count().' places'
            : $places->map(fn (Restaurant $r) => $r->name_en ?: $r->name_ar)->join(' & ');

        return $head.($type === 'free_delivery' ? ' from ' : ' at ').$names;
    }

    private static function number(float $value): string
    {
        return fmod($value, 1.0) === 0.0 ? (string) (int) $value : number_format($value, 2, '.', '');
    }
}
