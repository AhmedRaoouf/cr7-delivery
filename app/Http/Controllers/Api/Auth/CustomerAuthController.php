<?php

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\SendOtpAction;
use App\Actions\Auth\VerifyOtpAction;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Support\ApiResponse;
use App\Support\Otp;
use Illuminate\Http\JsonResponse;

/** FR-AUTH-01/02/03 — the customer shell never has a password. */
class CustomerAuthController extends Controller
{
    public function sendOtp(SendOtpRequest $request, SendOtpAction $action): JsonResponse
    {
        $purpose = OtpPurpose::from($request->string('purpose')->isNotEmpty() ? $request->string('purpose')->value() : 'login');

        $action->execute($request->string('phone'), $purpose);

        // Non-null only while the code is a fixed staging one, in which case it
        // is public already. `ApiResponse` drops the key when it is null, so a
        // real deployment answers exactly what it answered before.
        $staticOtp = Otp::echoable();

        return ApiResponse::success(
            $staticOtp === null ? null : ['static_otp' => $staticOtp],
            'تم إرسال رمز التحقق',
        );
    }

    public function verifyOtp(VerifyOtpRequest $request, VerifyOtpAction $action): JsonResponse
    {
        $purpose = OtpPurpose::from($request->string('purpose')->isNotEmpty() ? $request->string('purpose')->value() : 'login');

        $result = $action->execute($request->string('phone'), $request->string('code'), $purpose);

        return ApiResponse::success([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
            'is_new_user' => $result['is_new_user'],
        ], 'تم تسجيل الدخول بنجاح');
    }
}
