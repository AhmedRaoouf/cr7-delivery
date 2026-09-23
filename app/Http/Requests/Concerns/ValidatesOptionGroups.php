<?php

namespace App\Http\Requests\Concerns;

use App\Support\Catalog\OptionGroupWriter;

/**
 * D-164 / D-165 — one set of rules for an option group and an option, shared by the
 * place's requests and the admin's, so the two surfaces cannot drift. What the rules
 * cannot say (a single group takes one; `min ≤ max`; one default in a single group) is
 * the job of {@see OptionGroupWriter}.
 */
trait ValidatesOptionGroups
{
    /** @return array<string, list<mixed>> */
    protected function groupRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name_ar' => [$required, 'string', 'max:255'],
            'name_en' => [$required, 'string', 'max:255'],
            'selection_type' => [$required, 'in:single,multiple'],
            // doc 78 — `add`: each option is an extra on the dish; `final`: the chosen
            // option's price *is* the dish's price («نص كيلو = 500»). Omitted → `add`.
            'price_mode' => ['sometimes', 'nullable', 'in:add,final'],
            'is_required' => ['sometimes', 'nullable', 'boolean'],
            'min_select' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:50'],
            'max_select' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
            'sort_order' => ['sometimes', 'nullable', 'integer'],
            // A group can arrive with its options, in one transaction (doc 76 §2.2).
            ...($creating ? [
                'options' => ['sometimes', 'array', 'max:50'],
                ...collect($this->optionRules(true))->mapWithKeys(fn ($rules, $field) => ["options.*.{$field}" => $rules])->all(),
            ] : []),
        ];
    }

    /** @return array<string, list<mixed>> */
    protected function optionRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name_ar' => [$required, 'string', 'max:255'],
            'name_en' => [$required, 'string', 'max:255'],
            // The option's price, never negative (D-165). In an `add` group it is added to
            // the dish; in a `final` group it is the dish's price, and 0 means «the dish's
            // own price» (doc 78).
            'price_delta' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'is_available' => ['sometimes', 'nullable', 'boolean'],
            'is_default' => ['sometimes', 'nullable', 'boolean'],
            'sort_order' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    protected function optionMessages(): array
    {
        return [
            'price_delta.min' => 'سعر الخيار لا يمكن أن يكون سالبًا',
            'options.*.price_delta.min' => 'سعر الخيار لا يمكن أن يكون سالبًا',
            'max_select.min' => 'الحد الأقصى للاختيار يجب أن يكون ‪1‬ على الأقل',
        ];
    }
}
