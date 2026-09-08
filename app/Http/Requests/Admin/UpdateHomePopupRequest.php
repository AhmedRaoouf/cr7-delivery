<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ValidatesHomePopupLink;
use App\Models\HomePopup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The same field rules as `StoreHomePopupRequest`, judged against the row as it will be
 * *after* the merge.
 *
 * A PATCH that sends only `link_type=product` is switching a live card's destination,
 * and has to be refused unless a `product_id` is already on the row — which is why the
 * coherence check is handed the model. See `ValidatesHomePopupLink`.
 */
class UpdateHomePopupRequest extends FormRequest
{
    use ValidatesHomePopupLink;

    /** The route carries `permission:banners.manage`; this is not a second gate. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return (new StoreHomePopupRequest)->rules();
    }

    public function withValidator(Validator $validator): void
    {
        $popup = $this->route('homePopup');

        $validator->after(fn (Validator $v) => $this->validateHomePopupLink(
            $v,
            $popup instanceof HomePopup ? $popup : null,
        ));
    }
}
