<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreFaqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('content.manage');
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'question_ar' => [$required, 'string', 'max:500'],
            'question_en' => [$required, 'string', 'max:500'],
            'answer_ar' => [$required, 'string'],
            'answer_en' => [$required, 'string'],
            'category' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}
