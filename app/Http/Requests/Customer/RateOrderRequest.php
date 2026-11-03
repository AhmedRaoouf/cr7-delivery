<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class RateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'restaurants' => ['nullable', 'array'],
            'restaurants.*.restaurant_id' => ['required', 'exists:restaurants,id'],
            'restaurants.*.stars' => ['required', 'integer', 'between:1,5'],
            'restaurants.*.tags' => ['nullable', 'array'],
            'restaurants.*.comment' => ['nullable', 'string', 'max:500'],
            'driver' => ['nullable', 'array'],
            'driver.driver_id' => ['required_with:driver', 'exists:drivers,id'],
            'driver.stars' => ['required_with:driver', 'integer', 'between:1,5'],
            'driver.tags' => ['nullable', 'array'],
            'driver.comment' => ['nullable', 'string', 'max:500'],
            // doc 99 §6 — D-174: every captain who carried a part of the basket, each rated
            // on its own. `driver` stays for an app that knows one captain.
            'drivers' => ['nullable', 'array', 'max:3'],
            'drivers.*.driver_id' => ['required', 'integer', 'exists:drivers,id'],
            'drivers.*.stars' => ['required', 'integer', 'between:1,5'],
            'drivers.*.tags' => ['nullable', 'array'],
            'drivers.*.comment' => ['nullable', 'string', 'max:500'],
        ];
    }
}
