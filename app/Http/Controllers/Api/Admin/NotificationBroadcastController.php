<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\Notifications\FcmClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * doc 08 §7 — POST /admin/notifications/broadcast {audience, title_ar, title_en, body_ar, body_en, deep_link}.
 *
 * **It used to write rows and send nothing.** `Notification::insert()` put a row in
 * front of every recipient's notification centre and the method ended there — no push,
 * no delivery record, no FCM call anywhere in the file. An operator pressing «إرسال»
 * got «تم إرسال الإشعار بنجاح» and not one phone made a sound. The only way to notice
 * was to hold two devices.
 *
 * The send is deliberately **not** [NotificationDispatcher::send]. That method is built
 * around `NotificationCopy`'s `type → copy` table, and a broadcast has no type: the
 * operator typed the words. Going through it would either need a fake table entry or a
 * second copy path inside it. So the row insert stays bulk — one query per 500 people,
 * because this is the only place in the application that writes to every account at
 * once — and the push is sent straight through [FcmClient] in chunks.
 */
class NotificationBroadcastController extends Controller
{
    /** One FCM pool per chunk. Large enough to be few round trips, small enough not to hold the request. */
    private const PUSH_CHUNK = 250;

    public function store(Request $request, FcmClient $fcm): JsonResponse
    {
        $validated = $request->validate([
            'audience' => ['required', 'in:all,customers,drivers,restaurants'],
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['required', 'string', 'max:255'],
            'body_ar' => ['required', 'string', 'max:1000'],
            'body_en' => ['required', 'string', 'max:1000'],
            'deep_link' => ['nullable', 'string'],
        ]);

        $type = match ($validated['audience']) {
            'customers' => UserType::Customer,
            'drivers' => UserType::Driver,
            'restaurants' => UserType::Restaurant,
            default => null,
        };

        $userIds = User::query()
            ->when($type, fn ($q) => $q->where('type', $type))
            ->where('is_active', true)
            ->pluck('id');

        $rows = $userIds->map(fn ($id) => [
            'user_id' => $id,
            'type' => 'admin_broadcast',
            'title_key' => $validated['title_ar'],
            'payload' => json_encode([
                'title_ar' => $validated['title_ar'],
                'title_en' => $validated['title_en'],
                'body_ar' => $validated['body_ar'],
                'body_en' => $validated['body_en'],
            ], JSON_UNESCAPED_UNICODE),
            'deep_link' => $validated['deep_link'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            Notification::insert($chunk);
        }

        $pushed = $this->push($fcm, $userIds->all(), $validated);

        AuditLogger::record($request->user(), 'notifications.broadcast', $request->user(), [
            'audience' => $validated['audience'],
            'recipients' => count($rows),
            'pushed' => $pushed,
        ]);

        return ApiResponse::success(
            ['recipients' => count($rows), 'devices' => $pushed],
            'تم إرسال الإشعار بنجاح',
            status: 201,
        );
    }

    /**
     * @param  list<int>  $userIds
     * @param  array<string, string|null>  $copy
     * @return int devices FCM accepted
     */
    private function push(FcmClient $fcm, array $userIds, array $copy): int
    {
        if ($userIds === [] || ! $fcm->isConfigured()) {
            return 0;
        }

        $sent = 0;
        $dead = [];

        // Each device gets the copy in **its owner's** language. The row in the centre
        // carries both and is picked at read time, but a push is rendered once, at
        // send time, and there is nothing to re-pick later.
        DeviceToken::query()
            ->whereIn('user_id', $userIds)
            ->with('user:id,locale')
            ->chunkById(self::PUSH_CHUNK, function ($devices) use ($fcm, $copy, &$sent, &$dead) {
                $messages = $devices->map(function (DeviceToken $device) use ($copy) {
                    $isEnglish = str_starts_with((string) ($device->user?->locale ?: 'ar'), 'en');

                    return [
                        'token' => $device->token,
                        'title' => $isEnglish ? $copy['title_en'] : $copy['title_ar'],
                        'body' => $isEnglish ? $copy['body_en'] : $copy['body_ar'],
                        'data' => array_filter([
                            'type' => 'admin_broadcast',
                            'kind' => 'broadcast',
                            'deep_link' => $copy['deep_link'] ?? null,
                        ], fn ($value) => $value !== null),
                        'channel' => 'default',
                    ];
                })->values()->all();

                try {
                    $results = $fcm->send($messages);
                } catch (\Throwable $e) {
                    // `FcmClient` guarantees it does not throw; if that ever stops being
                    // true, a broadcast must still not 500 after the rows are written.
                    Log::warning('broadcast push failed', ['error' => $e->getMessage()]);

                    return;
                }

                foreach ($devices->values() as $index => $device) {
                    $result = $results[$index] ?? null;

                    if ($result === null) {
                        continue;
                    }

                    if ($result['ok']) {
                        $sent++;
                    }

                    if ($result['unregistered']) {
                        $dead[] = $device->id;
                    }
                }
            });

        // Same rule the per-user dispatcher follows: an uninstalled app's token never
        // comes back, and left in place it is one guaranteed failure on every future
        // broadcast for as long as the account exists.
        if ($dead !== []) {
            DB::table('device_tokens')->whereIn('id', $dead)->delete();
        }

        return $sent;
    }
}
