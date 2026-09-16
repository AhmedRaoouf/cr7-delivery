<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Auth\SessionClient;
use App\Support\Auth\SessionIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * D-120 — the two ways a session continues without a sign-in. Everything they decide is
 * in {@see SessionIssuer}; this only reads the request and answers.
 */
class SessionController extends Controller
{
    /**
     * `POST /auth/refresh` — public, because the access token it replaces has usually
     * already expired, and a route behind `auth:sanctum` could never be reached at the one
     * moment it is needed.
     */
    public function refresh(Request $request, SessionIssuer $sessions): JsonResponse
    {
        $validated = $request->validate([
            'refresh_token' => ['required', 'string', 'max:255'],
        ]);

        return ApiResponse::success(
            $sessions->refresh($validated['refresh_token'], SessionClient::fromRequest($request)),
        );
    }

    /** `POST /auth/session/upgrade` — the bearer is a legacy token. */
    public function upgrade(Request $request, SessionIssuer $sessions): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        // Only a real, stored token can be exchanged — never a test double or a cookie
        // session's `TransientToken`, neither of which is something to delete.
        if (! $token instanceof PersonalAccessToken || ! $token->exists) {
            throw new ApiException('session_already_refreshable', 'هذه الجلسة تتجدد تلقائيًا بالفعل', 409);
        }

        return ApiResponse::success(
            $sessions->upgrade($request->user(), $token, SessionClient::fromRequest($request)),
        );
    }
}
