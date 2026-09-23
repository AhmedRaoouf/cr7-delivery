<?php

namespace App\Support\Catalog;

use App\Enums\OptionPriceMode;
use App\Enums\OptionSelectionType;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * D-164 / D-165 — every write to a product's option groups and options, for the place
 * (`/restaurant/…`) and the admin (`/admin/…`) alike. The controllers only check who
 * may touch the row; what a coherent group looks like is decided here, once.
 *
 * The rules used to live in nobody: a single group saved with `max_select: 3` took
 * three sizes, `min_select 4 / max_select 2` saved, and a required group could ask
 * for zero. The server now stores a coherent group whatever a client sent, and refuses
 * only what cannot be made coherent.
 */
class OptionGroupWriter
{
    /** @param  array<string, mixed>  $data */
    public static function create(Product $product, array $data): ProductOptionGroup
    {
        return DB::transaction(function () use ($product, $data): ProductOptionGroup {
            $options = $data['options'] ?? [];
            unset($data['options']);

            $group = new ProductOptionGroup(['product_id' => $product->id]);
            $group->fill(self::normalise($data, null, count($options)));
            self::assertOnePriceGroup($product->id, $group);
            $group->sort_order ??= (int) $product->optionGroups()->max('sort_order') + 1;
            $group->save();

            foreach (array_values($options) as $index => $option) {
                $group->options()->create(self::optionAttributes($option) + ['sort_order' => $option['sort_order'] ?? $index]);
            }

            self::settleDefaults($group);

            return self::present($group);
        });
    }

    /** @param  array<string, mixed>  $data */
    public static function update(ProductOptionGroup $group, array $data): ProductOptionGroup
    {
        return DB::transaction(function () use ($group, $data): ProductOptionGroup {
            unset($data['options']);
            $group->fill(self::normalise($data, $group, $group->options()->count()));
            self::assertOnePriceGroup((int) $group->product_id, $group);
            $group->save();

            self::settleDefaults($group);

            return self::present($group);
        });
    }

    /** @param  array<string, mixed>  $data */
    public static function saveOption(ProductOptionGroup $group, ?ProductOption $option, array $data): ProductOption
    {
        return DB::transaction(function () use ($group, $option, $data): ProductOption {
            $attributes = self::optionAttributes($data);

            if ($option === null) {
                $attributes['sort_order'] ??= (int) $group->options()->max('sort_order') + 1;
                $option = $group->options()->create($attributes);
            } else {
                $option->update($attributes);
            }

            if ($option->is_default) {
                self::makeDefault($group, $option);
            }

            return $option->fresh();
        });
    }

    /**
     * `ids` must name exactly the current rows; each takes its index as `sort_order`.
     *
     * @param  Collection<int, ProductOptionGroup|ProductOption>  $rows
     * @param  array<int, int|string>  $ids
     */
    public static function reorder(Collection $rows, array $ids): void
    {
        $wanted = collect($ids)->map(fn ($id) => (int) $id);
        $have = $rows->pluck('id')->map(fn ($id) => (int) $id);

        if ($wanted->count() !== $have->count() || $wanted->unique()->count() !== $wanted->count() || $wanted->diff($have)->isNotEmpty()) {
            throw new ApiException('validation_failed', 'ترتيب غير صالح — أرسل كل العناصر مرة واحدة', 422, [
                'ids' => ['يجب أن تحتوي القائمة على كل العناصر الحالية بلا تكرار'],
            ]);
        }

        DB::transaction(function () use ($rows, $wanted): void {
            $byId = $rows->keyBy('id');

            foreach ($wanted->values() as $index => $id) {
                $row = $byId[$id];

                if ((int) $row->sort_order !== $index) {
                    $row->forceFill(['sort_order' => $index])->save();
                }
            }
        });
    }

    /** The group as every staff write answers it: with its options, in order. */
    public static function present(ProductOptionGroup $group): ProductOptionGroup
    {
        return $group->fresh()->load(['options' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalise(array $data, ?ProductOptionGroup $current, int $optionCount): array
    {
        $mode = OptionPriceMode::from($data['price_mode'] ?? $current?->price_mode?->value ?? 'add');
        $type = OptionSelectionType::from($data['selection_type'] ?? $current?->selection_type?->value ?? 'single');

        // doc 78 — a group that sets the price is one size, so it takes exactly one. A
        // client that only switched the mode gets a single group; one that asked for
        // both «multiple» and «final» in the same breath is told why not.
        if ($mode === OptionPriceMode::Final && $type === OptionSelectionType::Multiple) {
            if (($data['selection_type'] ?? null) === 'multiple') {
                throw new ApiException('validation_failed', 'مجموعة تحدد السعر تسمح باختيار واحد فقط', 422, [
                    'selection_type' => ['مجموعة تحدد السعر تسمح باختيار واحد فقط'],
                ]);
            }

            $type = OptionSelectionType::Single;
        }

        $required = (bool) (array_key_exists('is_required', $data) ? $data['is_required'] : ($current?->is_required ?? false));

        if ($type === OptionSelectionType::Single) {
            $min = $required ? 1 : 0;
            $max = 1;
        } else {
            $max = (int) ($data['max_select'] ?? ($current !== null && $current->selection_type === OptionSelectionType::Multiple ? $current->max_select : max(1, $optionCount)));
            $max = max(1, $max);
            $min = $required ? max(1, (int) ($data['min_select'] ?? $current?->min_select ?? 1)) : 0;

            if ($min > $max) {
                throw new ApiException('validation_failed', 'الحد الأدنى للاختيار أكبر من الحد الأقصى', 422, [
                    'min_select' => ['الحد الأدنى للاختيار أكبر من الحد الأقصى'],
                ]);
            }
        }

        return array_filter([
            'name_ar' => $data['name_ar'] ?? null,
            'name_en' => $data['name_en'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
        ], fn ($value) => $value !== null) + [
            'selection_type' => $type,
            'price_mode' => $mode,
            'is_required' => $required,
            'min_select' => $min,
            'max_select' => $max,
        ];
    }

    /** doc 78 — one dish has one price: at most one group may set it. */
    private static function assertOnePriceGroup(int $productId, ProductOptionGroup $group): void
    {
        if ($group->price_mode !== OptionPriceMode::Final) {
            return;
        }

        $other = ProductOptionGroup::query()
            ->where('product_id', $productId)
            ->where('price_mode', OptionPriceMode::Final->value)
            ->when($group->exists, fn ($q) => $q->whereKeyNot($group->id))
            ->exists();

        if ($other) {
            throw new ApiException('validation_failed', 'لهذا الصنف مجموعة أخرى تحدد السعر — يمكن لمجموعة واحدة فقط أن تحدده', 422, [
                'price_mode' => ['لهذا الصنف مجموعة أخرى تحدد السعر'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function optionAttributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip(['name_ar', 'name_en', 'price_delta', 'is_available', 'is_default', 'sort_order']));

        // A cleared price field means «adds nothing», and a null flag means the default.
        if (array_key_exists('price_delta', $attributes)) {
            $attributes['price_delta'] = (float) ($attributes['price_delta'] ?? 0);
        }

        foreach (['is_available' => true, 'is_default' => false] as $flag => $fallback) {
            if (array_key_exists($flag, $attributes) && $attributes[$flag] === null) {
                $attributes[$flag] = $fallback;
            }
        }

        if (array_key_exists('sort_order', $attributes) && $attributes['sort_order'] === null) {
            unset($attributes['sort_order']);
        }

        return $attributes;
    }

    /**
     * A single group has at most one default: this one. A multiple group may not hold
     * more defaults than it lets the customer choose.
     */
    private static function makeDefault(ProductOptionGroup $group, ProductOption $option): void
    {
        $group->refresh();

        if ($group->selection_type === OptionSelectionType::Single) {
            $group->options()->whereKeyNot($option->id)->where('is_default', true)->update(['is_default' => false]);

            return;
        }

        if ($group->options()->where('is_default', true)->count() > max(1, (int) $group->max_select)) {
            throw new ApiException('validation_failed', "عدد الخيارات الافتراضية أكبر من الحد الأقصى للاختيار ({$group->max_select})", 422, [
                'is_default' => ['عدد الخيارات الافتراضية أكبر من الحد الأقصى للاختيار'],
            ]);
        }
    }

    /**
     * After a group-level write: a single group keeps only its first default, a multiple
     * group only as many as it allows — the rest are cleared rather than refused, since
     * the operator changed the group, not the options.
     */
    private static function settleDefaults(ProductOptionGroup $group): void
    {
        $allowed = $group->selection_type === OptionSelectionType::Single ? 1 : max(1, (int) $group->max_select);

        $extra = $group->options()
            ->where('is_default', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->slice($allowed);

        if ($extra->isNotEmpty()) {
            ProductOption::query()->whereIn('id', $extra)->update(['is_default' => false]);
        }
    }
}
