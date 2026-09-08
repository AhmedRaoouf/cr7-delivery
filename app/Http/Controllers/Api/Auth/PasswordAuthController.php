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
        // `app` is every shell. The mobile binary no longer asks «عميل ولا كابتن» — one
        // screen takes a phone and a password, and `user.type` in the answer is what
        // sends the customer to the browse tabs, the captain to the duty card, and —
        // since D-70 — the place to its own board and the admin to the console.
        //
        // The dashboards still name exactly one type each, so this widens the phone
        // and nothing else: a captain's password on `admin.` is refused by the branch
        // below it, untouched.
        $expected = match ($request->header('X-App')) {
            'restaurant' => [UserType::Restaurant],
            'admin' => [UserType::Admin],
            'app' => [UserType::Customer, UserType::Driver, UserType::Restaurant, UserType::Admin],
            default => throw new ApiException('wrong_client', 'هذا الحساب لا يمكنه الدخول من هذه الواجهة', 403),
        };

        $result = $action->execute($request->string('login'), $request->string('password'), $expected);

        return ApiResponse::success([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
        ], 'تم تسجيل الدخول بنجاح');
    }
}
