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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-AUTH-01/02/03 — the customer shell never has a password. */
class CustomerAuthController extends Controller
{
    public function sendOtp(SendOtpRequest $request, SendOtpAction $action): JsonResponse
    {
        $action->execute($request->string('phone'), OtpPurpose::Login);

        return ApiResponse::success(message: 'تم إرسال رمز التحقق');
    }

    public function verifyOtp(VerifyOtpRequest $request, VerifyOtpAction $action): JsonResponse
    {
        $result = $action->execute($request->string('phone'), $request->string('code'), OtpPurpose::Login);

        return ApiResponse::success([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
        ], 'تم تسجيل الدخول بنجاح');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(message: 'تم تسجيل الخروج');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return ApiResponse::success(message: 'تم تسجيل الخروج من كل الأجهزة');
    }
}
