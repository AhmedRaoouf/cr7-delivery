<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\StoreDeviceRequest;
use App\Models\DeviceToken;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * doc 08 §2 — POST/DELETE /devices: register or unregister an FCM token.
 *
 * **A phone belongs to one account at a time** (operator, 2026-09-11: «لما اعمل تسجيل
 * خروج وادخل بـ role تانية يجيلي بتاع الـ role الأخيرة، مش عايز حاجة تجيلي من الأولى»).
 *
 * It did not. The unique index is `(user_id, token)` and nothing ever looked the token
 * up across accounts, so signing out of a captain account and into an admin one left
 * **two** live rows carrying the same FCM token — and `NotificationDispatcher` sends to
 * every token a user has. Every captain offer and every customer update kept arriving on
 * a phone whose operator had signed in as somebody else, and a tap on one of them opened
 * an order the new account cannot read (404) on a screen that draws an empty order as if
 * it were real.
 *
 * So a registration now *claims* the token and the device: both are removed from every
 * other account first, in one transaction.
 */
class DeviceController extends Controller
{
    public function store(StoreDeviceRequest $request): JsonResponse
    {
        // `validated()`, not `string()`. The latter hands back a `Stringable`, and
        // `platform` and `app` are enum-cast columns: Eloquent calls `Enum::from()` on
        // the object and throws `ValueError`, so a registration that passed validation
        // answered 500. Invisible until the 422 in front of it was fixed, and invisible
        // then too — the app logs the failure and moves on rather than showing a
        // customer an error about a feature they never asked for.
        $validated = $request->validated();
        $user = $request->user();

        $device = DB::transaction(function () use ($user, $validated) {
            // The same physical phone, and the same push token, under any other account.
            DeviceToken::query()
                ->where('user_id', '!=', $user->id)
                ->where(fn ($q) => $q
                    ->where('token', $validated['token'])
                    ->orWhere('device_id', $validated['device_id']))
                ->delete();

            // The same phone under *this* account with a rotated token: keyed on the
            // device, so the row is updated rather than duplicated. And a token that
            // arrived here under a different device id of ours is the same phone
            // reporting a new id — drop it rather than leave two rows that both send.
            $user->deviceTokens()
                ->where('token', $validated['token'])
                ->where('device_id', '!=', $validated['device_id'])
                ->delete();

            return $user->deviceTokens()->updateOrCreate(
                ['device_id' => $validated['device_id']],
                [
                    'token' => $validated['token'],
                    'platform' => $validated['platform'],
                    'app' => $validated['app'],
                    // Written on every registration, so a phone updated from the old APK
                    // starts ringing the first time the new build registers — and one
                    // rolled back stops.
                    'capabilities' => array_values(array_unique($validated['capabilities'] ?? [])),
                    'app_version' => $validated['app_version'] ?? null,
                    'last_used_at' => now(),
                ],
            );
        });

        return ApiResponse::success($device, 'تم تسجيل الجهاز بنجاح');
    }

    /**
     * Unregister. The client calls this **before** `/auth/logout`, while it still has a
     * token to authenticate with; `AuthController::logout` repeats it from the header for
     * the sign-out that forgot to.
     */
    public function destroy(Request $request): JsonResponse
    {
        $deviceId = $request->header('X-Device-Id') ?? $request->string('device_id')->value();
        $token = $request->string('token')->value();

        self::forget($request->user(), $deviceId, $token);

        return ApiResponse::success(message: 'تم إلغاء تسجيل الجهاز بنجاح');
    }

    /** Delete this user's rows for a device id and/or a push token. Shared with logout. */
    public static function forget(User $user, ?string $deviceId, ?string $token): int
    {
        if (($deviceId === null || $deviceId === '') && ($token === null || $token === '')) {
            return 0;
        }

        return $user->deviceTokens()
            ->where(function ($q) use ($deviceId, $token) {
                if ($deviceId !== null && $deviceId !== '') {
                    $q->orWhere('device_id', $deviceId);
                }

                if ($token !== null && $token !== '') {
                    $q->orWhere('token', $token);
                }
            })
            ->delete();
    }
}
