<?php

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\PasswordLoginAction;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-AUTH-04/05 — driver, restaurant and admin: Admin-created accounts, phone/email + password. */
class PasswordAuthController extends Controller
{
    public function loginDriver(LoginRequest $request, PasswordLoginAction $action): JsonResponse
    {
        return $this->login($request, $action, UserType::Driver);
    }

    public function loginRestaurant(LoginRequest $request, PasswordLoginAction $action): JsonResponse
    {
        return $this->login($request, $action, UserType::Restaurant);
    }

    public function loginAdmin(LoginRequest $request, PasswordLoginAction $action): JsonResponse
    {
        return $this->login($request, $action, UserType::Admin);
    }

    private function login(LoginRequest $request, PasswordLoginAction $action, UserType $type): JsonResponse
    {
        $result = $action->execute($request->string('identifier'), $request->string('password'), $type);

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
}
