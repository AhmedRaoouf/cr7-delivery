<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ValidatesLogin;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    use ValidatesLogin;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * `login` is a phone number — or an admin's email (D-107).
     *
     * It is normalised before the rule runs, so `+20 109 000 9599`, `0020…`, `109…` and
     * `01090009599` are one account. Something that is neither a phone nor an email is
     * refused here rather than three layers down as «بيانات الدخول غير صحيحة», which
     * reads like a wrong password.
     */
    protected function prepareForValidation(): void
    {
        $this->normaliseLoginField();
    }

    public function rules(): array
    {
        return [
            'login' => $this->loginRules(),
            'password' => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->loginMessages() + [
            'password.required' => 'أدخل كلمة المرور',
        ];
    }
}
