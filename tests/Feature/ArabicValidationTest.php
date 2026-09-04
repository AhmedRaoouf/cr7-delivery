<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * `SetLocaleFromHeader` has called `App::setLocale('ar')` on every request since
 * launch, and there was no `lang/ar` for it to find. An admin toggling the direct-orders
 * switch on `ADM-48` was told «The enabled field is required.» in the middle of an
 * Arabic screen — English text, and a field named after a database column.
 */
class ArabicValidationTest extends TestCase
{
    private function messageFor(array $data, array $rules): string
    {
        App::setLocale('ar');

        return Validator::make($data, $rules)->errors()->first();
    }

    public function test_a_required_field_is_reported_in_arabic(): void
    {
        $message = $this->messageFor([], ['enabled' => ['required', 'boolean']]);

        $this->assertStringNotContainsString('field is required', $message);
        $this->assertStringContainsString('مطلوب', $message);
    }

    public function test_the_field_is_named_the_way_an_operator_would_say_it(): void
    {
        // Not «حقل enabled مطلوب», which is barely better than the English it replaced.
        $this->assertStringContainsString('التفعيل', $this->messageFor([], ['enabled' => ['required']]));
        $this->assertStringContainsString('الكابتن', $this->messageFor([], ['driver_id' => ['required']]));
        $this->assertStringContainsString('كلمة المرور', $this->messageFor([], ['password' => ['required']]));
        $this->assertStringContainsString('الصورة', $this->messageFor([], ['image' => ['required']]));
    }

    public function test_the_rules_an_upload_actually_hits_are_translated(): void
    {
        $this->assertStringContainsString('صورة', $this->messageFor(['image' => 'x'], ['image' => ['image']]));
        $this->assertStringContainsString('رقمًا', $this->messageFor(['price' => 'x'], ['price' => ['numeric']]));
        $this->assertStringContainsString('صالحًا', $this->messageFor(['email' => 'x'], ['email' => ['email']]));
    }

    public function test_numbers_inside_a_message_stay_western_digits(): void
    {
        // doc 13 §4 — Arabic-Indic digits are never used anywhere in this product, and
        // a `:max` the framework substitutes must not arrive as ٥٠٠.
        $message = $this->messageFor(['note' => str_repeat('x', 600)], ['note' => ['max:500']]);

        $this->assertStringContainsString('500', $message);
        $this->assertDoesNotMatchRegularExpression('/[٠-٩]/u', $message);
    }

    public function test_english_still_answers_in_english(): void
    {
        App::setLocale('en');

        $message = Validator::make([], ['enabled' => ['required']])->errors()->first();

        $this->assertStringContainsString('required', $message);
    }
}
