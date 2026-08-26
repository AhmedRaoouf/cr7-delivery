<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §7 — POST /admin/notifications/broadcast {audience, title_ar, title_en, body_ar, body_en, deep_link}. */
class NotificationBroadcastController extends Controller
{
    public function store(Request $request): JsonResponse
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

        $userIds = User::query()->when($type, fn ($q) => $q->where('type', $type))->where('is_active', true)->pluck('id');

        $rows = $userIds->map(fn ($id) => [
            'user_id' => $id,
            'type' => 'admin_broadcast',
            'title_key' => $validated['title_ar'],
            'payload' => json_encode(['title_ar' => $validated['title_ar'], 'title_en' => $validated['title_en'], 'body_ar' => $validated['body_ar'], 'body_en' => $validated['body_en']]),
            'deep_link' => $validated['deep_link'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            \App\Models\Notification::insert($chunk);
        }

        AuditLogger::record($request->user(), 'notifications.broadcast', $request->user(), ['audience' => $validated['audience'], 'recipients' => count($rows)]);

        return ApiResponse::success(['recipients' => count($rows)], 'تم إرسال الإشعار بنجاح', status: 201);
    }
}
