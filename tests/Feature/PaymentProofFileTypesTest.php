<?php

namespace Tests\Feature;

use App\Http\Requests\Customer\SubmitPaymentProofRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The request and `FileUploader::storePrivate()` must agree on what a receipt may be:
 * a WEBP passed the uploader and was refused by the request, so a customer whose phone
 * saved the screenshot as WEBP could never attach it.
 */
class PaymentProofFileTypesTest extends TestCase
{
    /** @dataProvider accepted */
    public function test_a_receipt_image_type_the_uploader_stores_passes_validation(string $name): void
    {
        $v = Validator::make(
            ['image' => UploadedFile::fake()->image($name, 800, 600), 'amount_claimed' => 125],
            (new SubmitPaymentProofRequest)->rules(),
        );

        $this->assertFalse($v->fails(), json_encode($v->errors()->all(), JSON_UNESCAPED_UNICODE));
    }

    public static function accepted(): array
    {
        return [['r.jpg'], ['r.jpeg'], ['r.png'], ['r.webp']];
    }
}
