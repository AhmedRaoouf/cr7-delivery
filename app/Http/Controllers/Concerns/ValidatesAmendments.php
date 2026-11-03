<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/** doc 99 §4, D-172 — the proposal's shape, shared by the place and the office. */
trait ValidatesAmendments
{
    /** @return array<string, mixed> */
    protected function validatedAmendment(Request $request, bool $prepRequired): array
    {
        return $request->validate([
            'prep_minutes' => [$prepRequired ? 'required' : 'nullable', 'integer', 'min:1', 'max:600'],
            'restaurant_order_ref' => ['nullable', 'string', 'max:32'],
            'lines' => ['nullable', 'array', 'max:100'],
            'lines.*.order_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:999'],
            'additions' => ['nullable', 'array', 'max:30'],
            'additions.*.product_id' => ['required', 'integer'],
            'additions.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
