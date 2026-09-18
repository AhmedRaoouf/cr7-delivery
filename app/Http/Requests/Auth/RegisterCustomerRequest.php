<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalisesPhone;
use App\Support\PhoneNumber;
use App\Support\Security\PasswordPolicy;
use App\Support\SettingsCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterCustomerRequest extends FormRequest
{
    use NormalisesPhone;

    public function authorize(): bool
    {
        return true;
    }

    /** The number is stored as eleven digits, so it is compared as eleven digits. */
    protected function prepareForValidation(): void
    {
        $this->normalisePhoneFields(['phone']);
    }

    public function rules(): array
    {
        return [
            // The proof `otp/verify` handed back. Optional only because D-106 lets the
            // operator switch the sign-up code off; with it on, a request without one is
            // refused `otp_required` by the action, not here, so the app is told *why*.
            'registration_token' => ['nullable', 'string', 'size:64'],
            // Only read on the code-less path — with a token, the phone is the one the
            // token proved, and a second one in the body is ignored.
            'phone' => [
                Rule::requiredIf(fn () => blank($this->input('registration_token'))
                    && ! SettingsCatalog::bool('auth.register_requires_otp')),
                'nullable', 'string', 'regex:'.PhoneNumber::PATTERN,
            ],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            // `confirmed` is deliberately not required: the app shows one field with a
            // reveal toggle, which is the pattern every Egyptian delivery app uses and
            // is measurably better than two fields on a phone keyboard.
            'password' => PasswordPolicy::rules(),
        ];
    }

    public function messages(): array
    {
        return [
            'password.min' => 'كلمة المرور يجب ألا تقل عن ‪8‬ أحرف',
            'name.required' => 'الاسم مطلوب',
            'phone.required' => 'أدخل رقم الهاتف',
            'phone.regex' => 'أدخل رقم هاتف مصري صحيح مكونًا من ‪11‬ رقمًا يبدأ بـ ‪01‬.',
        ];
    }
}
