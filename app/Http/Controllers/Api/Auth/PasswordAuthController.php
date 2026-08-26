<?php

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\PasswordLoginAction;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * FR-AUTH-04/05 — driver, restaurant and admin: Admin-created accounts, phone/email + password.
 * doc 08 §3 — one `/auth/login` endpoint; `X-App` says which shell is calling and therefore
 * which `users.type` is expected (doc 12 §1.1).
 */
class PasswordAuthController extends Controller
{
    public function login(LoginRequest $request, PasswordLoginAction $action): JsonResponse
    {
        $expectedType = match ($request->header('X-App')) {
            'restaurant' => UserType::Restaurant,
            'admin' => UserType::Admin,
            'app' => UserType::Driver,
            default => throw new ApiException('wrong_client', 'هذا الحساب لا يمكنه الدخول من هذه الواجهة', 403),
        };

        $result = $action->execute($request->string('login'), $request->string('password'), $expectedType);

        return ApiResponse::success([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
        ], 'تم تسجيل الدخول بنجاح');
    }
}
