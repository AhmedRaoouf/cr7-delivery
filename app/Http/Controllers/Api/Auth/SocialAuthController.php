<?php

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\LinkSocialAccountAction;
use App\Actions\Auth\SocialSignInAction;
use App\Enums\SocialProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SocialLinkRequest;
use App\Http\Requests\Auth\SocialSignInRequest;
use App\Http\Resources\UserResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * `CUS-07`'s two provider buttons, and `CUS-07a` behind them.
 *
 * `POST /auth/social` answers one of exactly two things in `data.status`, and the app
 * branches on that field rather than on a status code:
 *
 * * `signed_in` — plus `user` and `token`. A returning customer, no OTP.
 * * `needs_phone` — plus `link_ticket`, `expires_at`, and whatever `name` / `email` the
 *   provider gave up. **No provider returns a phone number**, so this is the honest end
 *   of what Apple and Google can do on their own; `POST /auth/social/link` finishes it.
 *
 * Anything else is a refusal in doc 08 §1's error envelope, with the same named codes the
 * rest of `Api/Auth/` uses — `wrong_client` when the identity belongs to a captain,
 * `account_suspended`, `social_token_invalid`, `social_not_configured`.
 */
class SocialAuthController extends Controller
{
    public function store(SocialSignInRequest $request, SocialSignInAction $action): JsonResponse
    {
        $provider = SocialProvider::from($request->string('provider')->value());

        $result = $action->execute($provider, $request->string('id_token')->value(), $request->input('name'));

        if ($result['status'] === 'signed_in') {
            return ApiResponse::success([
                'status' => 'signed_in',
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
            ], 'تم تسجيل الدخول بنجاح');
        }

        return ApiResponse::success([
            'status' => 'needs_phone',
            'link_ticket' => $result['ticket']->token,
            'expires_at' => $result['ticket']->expires_at->toIso8601String(),
            'name' => $result['name'],
            'email' => $result['email'],
        ], 'أدخل رقم هاتفك لإكمال حسابك');
    }

    public function link(SocialLinkRequest $request, LinkSocialAccountAction $action): JsonResponse
    {
        $result = $action->execute(
            $request->string('link_ticket')->value(),
            $request->string('phone')->value(),
            $request->string('code')->value(),
        );

        return ApiResponse::success([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
            'is_new_user' => $result['is_new_user'],
        ], 'تم تسجيل الدخول بنجاح');
    }
}
