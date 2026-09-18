<?php

namespace App\Http\Controllers\Api\Customer;

use App\Actions\Auth\SendOtpAction;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\AddressPhoneRequest;
use App\Http\Requests\Customer\VerifyAddressPhoneRequest;
use App\Support\AddressContactPhone;
use App\Support\ApiResponse;
use App\Support\OtpGuard;
use App\Support\OtpRequester;
use App\Support\OtpVerifier;
use Illuminate\Http\JsonResponse;

/**
 * D-112 — proving a second number before it goes on an address.
 *
 * The app does this inline, from the phone field of the address form: send a code to the
 * number the customer typed, take the code back, and the number is the account's to put
 * on any address from then on (`user_verified_phones`).
 *
 * It is the one code in the platform that proves a number **without** touching an account:
 * a `login` or `verify` code opens a session and creates a customer for a new phone, and
 * `address_phone` does neither. The number stays somebody else's phone that this customer
 * has shown they can reach.
 */
class AddressPhoneController extends Controller
{
    public function sendCode(AddressPhoneRequest $request, SendOtpAction $sendOtp): JsonResponse
    {
        if (! AddressContactPhone::allowed()) {
            throw AddressContactPhone::notAllowed();
        }

        $user = $request->user();
        $phone = $request->string('phone')->value();

        // Nothing to prove — the account's own number, or one it proved before. No SMS is
        // spent on either, and the form can save straight away.
        if (AddressContactPhone::isVerified($user, $phone)) {
            return ApiResponse::success([
                'phone' => $phone,
                'sent' => false,
                'already_verified' => true,
                'resend_in' => 0,
                'expires_in' => 0,
                'code_length' => OtpGuard::CODE_LENGTH,
                'max_attempts' => OtpGuard::maxAttempts(),
                'static_otp' => null,
            ], 'هذا الرقم مؤكد بالفعل');
        }

        $sent = $sendOtp->execute($phone, OtpPurpose::AddressPhone, requester: OtpRequester::fromRequest($request));

        return ApiResponse::success([
            'phone' => $phone,
            'sent' => true,
            'already_verified' => false,
            ...$sent,
        ], 'تم إرسال رمز التحقق');
    }

    public function verify(VerifyAddressPhoneRequest $request): JsonResponse
    {
        if (! AddressContactPhone::allowed()) {
            throw AddressContactPhone::notAllowed();
        }

        $user = $request->user();
        $phone = $request->string('phone')->value();

        if (! AddressContactPhone::isVerified($user, $phone)) {
            OtpVerifier::consume($phone, OtpPurpose::AddressPhone, $request->string('code')->value());

            $user->verifiedPhones()->firstOrCreate(['phone' => $phone], ['verified_at' => now()]);
        }

        return ApiResponse::success(['phone' => $phone, 'verified' => true], 'تم تأكيد الرقم');
    }
}
