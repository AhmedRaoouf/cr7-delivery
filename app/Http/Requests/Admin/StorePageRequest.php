<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('content.manage');
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            // The slug is the route key, so an update that sends the page's own
            // slug back was compared against the row it belongs to and refused
            // with «slug مستخدم من قبل» — on a slug that never changed. Any
            // editor that loads a page and posts it back hit it every time, and
            // the message named a field the operator had not touched.
            'slug' => [
                $required,
                'string',
                'max:100',
                Rule::unique('static_pages', 'slug')
                    ->ignore($this->route('slug'), 'slug'),
            ],
            'title_ar' => [$required, 'string', 'max:255'],
            'title_en' => [$required, 'string', 'max:255'],
            'body_ar' => [$required, 'string'],
            'body_en' => [$required, 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
