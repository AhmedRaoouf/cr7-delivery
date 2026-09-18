<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Models\OtpBlock;
use App\Models\SmsSend;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\BusinessTime;
use App\Support\OtpBudget;
use App\Support\OtpGuard;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Batch 68 — what the platform's verification messages cost today, and the numbers it
 * has stopped sending to.
 *
 * Global admins only, on the settings permissions: `settings.view` reads, `settings.manage`
 * blocks and unblocks. The budget these numbers are measured against is a setting, and the
 * phone numbers listed here are anybody's on the platform, so a city-scoped admin sees none
 * of it (doc 12 §10.1, `admin.global`). Every write is an `audit_logs` row.
 */
class OtpController extends Controller
{
    /** `GET /admin/otp/usage` */
    public function usage(): JsonResponse
    {
        $startOfDay = BusinessTime::startOfToday();
        $today = SmsSend::query()->where('created_at', '>=', $startOfDay);

        $byPurpose = array_fill_keys(array_map(fn (OtpPurpose $p) => $p->value, OtpPurpose::cases()), 0);
        foreach ((clone $today)->selectRaw('purpose, count(*) as sent')->groupBy('purpose')->pluck('sent', 'purpose') as $purpose => $sent) {
            $byPurpose[$purpose] = (int) $sent;
        }

        $top = (clone $today)
            ->selectRaw('phone, count(*) as sent_today')
            ->groupBy('phone')
            ->orderByDesc('sent_today')
            ->orderBy('phone')
            ->limit(10)
            ->get();

        $blocks = OtpBlock::query()->active()->whereIn('phone', $top->pluck('phone'))
            ->get()
            ->groupBy('phone')
            ->map(fn ($rows) => $rows->max('blocked_until'));

        return ApiResponse::success([
            'today' => [
                'sent' => OtpBudget::sentToday(),
                'budget' => OtpBudget::daily(),
                'shadow' => (clone $today)->where('kind', SmsSend::KIND_SHADOW)->count(),
            ],
            'hour' => [
                'sent' => OtpBudget::sentLastHour(),
                'budget' => OtpBudget::hourly(),
            ],
            'blocked_now' => OtpBlock::query()->active()->count(),
            'by_purpose' => $byPurpose,
            'top_numbers' => $top->map(fn ($row) => [
                'phone' => $row->phone,
                'sent_today' => (int) $row->sent_today,
                'blocked_until' => $blocks->get($row->phone)?->copy()->utc()->toIso8601String(),
            ])->values(),
        ]);
    }

    /** `GET /admin/otp/blocks?filter[search]=&filter[status]=active|all&page=&per_page=` */
    public function index(Request $request): JsonResponse
    {
        $search = preg_replace('/\D+/', '', PhoneNumber::normalise($request->string('filter.search')->value())) ?? '';
        $all = $request->string('filter.status')->value() === 'all';
        $perPage = max(1, min(100, $request->integer('per_page', 20) ?: 20));

        $blocks = OtpBlock::query()
            ->with('creator:id,name')
            ->unless($all, fn ($q) => $q->active())
            ->when($search !== '', fn ($q) => $q->where('phone', 'like', '%'.$search.'%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return ApiResponse::success(
            collect($blocks->items())->map(fn (OtpBlock $block) => $block->toApi())->values(),
            meta: [
                'current_page' => $blocks->currentPage(),
                'per_page' => $blocks->perPage(),
                'total' => $blocks->total(),
                'last_page' => $blocks->lastPage(),
            ],
        );
    }

    /**
     * `POST /admin/otp/blocks {phone, hours, note?, reason?}` — a block by hand. A block
     * already in force on the number is lifted in its favour, so the operator's decision
     * is the one that stands, shorter or longer.
     */
    public function store(Request $request): JsonResponse
    {
        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => PhoneNumber::normalise($request->input('phone'))]);
        }

        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:'.PhoneNumber::PATTERN],
            'hours' => ['required', 'integer', 'min:1', 'max:720'],
            'note' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', Rule::in([OtpBlock::REASON_ADMIN, OtpBlock::REASON_ABUSE])],
        ], [
            'phone.required' => 'أدخل رقم الهاتف',
            'phone.regex' => 'أدخل رقم هاتف صحيح',
            'hours.required' => 'أدخل مدة الحظر بالساعات',
            'hours.integer' => 'أدخل مدة الحظر بالساعات',
            'hours.min' => 'أقل مدة ساعة واحدة',
            'hours.max' => 'أطول مدة ‪720‬ ساعة',
        ]);

        $admin = $request->user();

        OtpBlock::query()->active()->where('phone', $data['phone'])->update([
            'lifted_at' => now(),
            'lifted_by' => $admin->id,
        ]);

        $block = OtpGuard::block(
            $data['phone'],
            $data['reason'] ?? OtpBlock::REASON_ADMIN,
            now()->addHours((int) $data['hours']),
            $data['note'] ?? null,
            $admin,
        );

        AuditLogger::record($admin, 'otp.block', $block, [
            'phone' => $block->phone,
            'reason' => $block->reason,
            'blocked_until' => $block->blocked_until->toIso8601String(),
            'note' => $block->note,
        ]);

        return ApiResponse::success($block->load('creator:id,name')->toApi(), 'تم حظر الرقم', status: 201);
    }

    /**
     * `DELETE /admin/otp/blocks/{block}` — unblock. Every block on that number is lifted,
     * and its own counts start over: hourly, daily, the escalating wait and the burned
     * codes. Idempotent: a block that already ended still starts the counts over.
     */
    public function destroy(Request $request, OtpBlock $block): JsonResponse
    {
        $admin = $request->user();
        $wasActive = $block->isActive();

        OtpGuard::release($block->phone, $admin);

        AuditLogger::record($admin, 'otp.unblock', $block, [
            'phone' => $block->phone,
            'counters_cleared' => true,
        ], [
            'blocked_until' => $block->blocked_until->toIso8601String(),
            'active' => $wasActive,
        ]);

        return ApiResponse::success($block->fresh()->load('creator:id,name')->toApi(), 'تم رفع الحظر عن الرقم');
    }
}
