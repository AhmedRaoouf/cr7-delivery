<?php

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\RegisterCustomerAction;
use App\Actions\Auth\SendOtpAction;
use App\Actions\Auth\VerifyOtpAction;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterCustomerRequest;
use App\Http\Requests\Auth\SendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Support\ApiResponse;
use App\Support\Auth\SessionClient;
use App\Support\OtpRequester;
use Illuminate\Http\JsonResponse;

/**
 * FR-AUTH-01/02/03 — the customer's own three requests.
 *
 * The shell used to be OTP-only; since 2026-09-07 a customer has a password like everyone
 * else, and «إنشاء حساب» is: send a code, verify it, choose a password. `verifyOtp` still
 * answers a session for `purpose = login`, because every account created before that day
 * has no password and OTP is the only way back in for them — and the way they get one is
 * «نسيت كلمة المرور», which is the same code down the same wire.
 *
 * D-106 — with `auth.register_requires_otp` off, `register` takes the phone itself and
 * the first two requests are skipped.
 */
class CustomerAuthController extends Controller
{
    public function sendOtp(SendOtpRequest $request, SendOtpAction $action): JsonResponse
    {
        $purpose = OtpPurpose::from($request->string('purpose')->isNotEmpty() ? $request->string('purpose')->value() : 'login');

        $sent = $action->execute($request->string('phone')->value(), $purpose, requester: OtpRequester::fromRequest($request));

        // `static_otp` is the operator's test code while `otp.mode = test` reaches this
        // send, and null otherwise — including every send for an admin's number (D-107).
        // The app prints it as «وضع التجربة» and prefills the field. Batch 68 — beside it,
        // `resend_in` (it escalates), `expires_in`, `code_length` and `max_attempts`.
        return ApiResponse::success($sent, 'تم إرسال رمز التحقق');
    }

    public function verifyOtp(VerifyOtpRequest $request, VerifyOtpAction $action): JsonResponse
    {
        $purpose = OtpPurpose::from($request->string('purpose')->isNotEmpty() ? $request->string('purpose')->value() : 'login');

        $result = $action->execute(
            $request->string('phone'),
            $request->string('code'),
            $purpose,
            SessionClient::fromRequest($request),
        );

        // Registration answers a token, not a session — the account does not exist yet.
        if (array_key_exists('registration_token', $result)) {
            return ApiResponse::success($result, 'تم التحقق من رقمك — اختر كلمة مرور');
        }

        // D-120 — `session` is `{token}` for a client without `X-Auth-Refresh`, so this is
        // `{user, token, is_new_user}` exactly as it always was.
        return ApiResponse::success([
            'user' => new UserResource($result['user']),
            ...$result['session'],
            'is_new_user' => $result['is_new_user'],
        ], 'تم تسجيل الدخول بنجاح');
    }

    public function register(RegisterCustomerRequest $request, RegisterCustomerAction $action): JsonResponse
    {
        $result = $action->execute(
            $request->string('registration_token')->value() ?: null,
            $request->string('name'),
            $request->string('password'),
            $request->string('phone')->value() ?: null,
            SessionClient::fromRequest($request),
        );

        return ApiResponse::success([
            'user' => new UserResource($result['user']),
            ...$result['session'],
        ], 'تم إنشاء حسابك بنجاح', status: 201);
    }
}
