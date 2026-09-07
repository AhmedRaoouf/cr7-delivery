<?php

namespace App\Support;

use App\Enums\UserType;
use App\Models\Complaint;
use App\Models\ComplaintMessage;
use App\Models\User;
use App\Support\Notifications\NotificationDispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * D-53's missing half: somebody has to be **told**.
 *
 * The two-sided complaints box was built and wired — the customer writes, the operator
 * reads on `ADM-41`, the restaurant reads its redacted mirror on `RES-31` — and not one
 * line of it sent a notification. A complaint was a row that sat there until a human
 * happened to open the right screen. Operator, 2026-09-08: «الوقت طالما حد اشتكى على
 * حد، المفروض يروح للأدمن والحاجات دي كلها».
 *
 * Every method here is best-effort and swallows: a complaint that could not be
 * announced is still a complaint, and failing the customer's «إرسال» because FCM was
 * unreachable would lose the thing they typed.
 *
 * **Redaction travels with the message.** An `internal` note is an operator talking to
 * another operator, and it is the one thing on this thread the complainant and the
 * restaurant must never receive — not in a push, and not in the preview line of one.
 */
class ComplaintNotifier
{
    /** How much of the message rides along in the push body. */
    private const EXCERPT = 90;

    public function __construct(private readonly NotificationDispatcher $notifications) {}

    /** A new ticket: every operator hears about it, and the restaurant if it is mirrored. */
    public function opened(Complaint $complaint): void
    {
        $this->guard(function () use ($complaint) {
            foreach ($this->operators() as $admin) {
                $this->notifications->send($admin, 'complaint.new_admin', [
                    'ticket' => $complaint->ticket_number,
                    'name' => $complaint->user?->name,
                    'category' => $complaint->category?->value ?? (string) $complaint->category,
                    'excerpt' => self::excerpt($complaint->description),
                ], deepLink: '/complaints/'.$complaint->id, data: self::route($complaint));
            }

            if ($complaint->visible_to_restaurant) {
                $this->notifications->send(
                    $complaint->restaurant?->owner,
                    'complaint.new_admin',
                    [
                        'ticket' => $complaint->ticket_number,
                        // First name only — doc 24 §4.2's redaction, in the push too.
                        'name' => Str::before((string) $complaint->user?->name, ' '),
                        'category' => $complaint->category?->value ?? (string) $complaint->category,
                        'excerpt' => self::excerpt($complaint->description),
                    ],
                    deepLink: '/complaints/'.$complaint->id,
                    data: self::route($complaint),
                );
            }
        });
    }

    /**
     * A reply. Who hears it depends on who wrote it — and an internal note reaches the
     * operators alone.
     */
    public function replied(Complaint $complaint, ComplaintMessage $message): void
    {
        $this->guard(function () use ($complaint, $message) {
            $role = $message->sender_role?->value ?? (string) $message->sender_role;
            $visibility = $message->visibility?->value ?? (string) $message->visibility;
            $excerpt = self::excerpt($message->message);

            if ($visibility === 'internal') {
                $this->tellOperators($complaint, $excerpt, $message->sender_id);

                return;
            }

            if ($role === 'admin') {
                $this->notifications->send($complaint->user, 'complaint.reply', [
                    'ticket' => $complaint->ticket_number,
                    'excerpt' => $excerpt,
                ], deepLink: 'cr7://complaints/'.$complaint->id, data: self::route($complaint));

                if ($complaint->visible_to_restaurant) {
                    $this->notifications->send($complaint->restaurant?->owner, 'complaint.reply', [
                        'ticket' => $complaint->ticket_number,
                        'excerpt' => $excerpt,
                    ], deepLink: '/complaints/'.$complaint->id, data: self::route($complaint));
                }

                return;
            }

            // A customer or a restaurant answered: the platform owns the thread, so the
            // operators hear it either way, and the restaurant hears the customer only
            // while the ticket is mirrored to it.
            $this->tellOperators($complaint, $excerpt, $message->sender_id);

            if ($role === 'customer' && $complaint->visible_to_restaurant) {
                $this->notifications->send($complaint->restaurant?->owner, 'complaint.reply', [
                    'ticket' => $complaint->ticket_number,
                    'excerpt' => $excerpt,
                ], deepLink: '/complaints/'.$complaint->id, data: self::route($complaint));
            }

            if ($role === 'restaurant') {
                $this->notifications->send($complaint->user, 'complaint.reply', [
                    'ticket' => $complaint->ticket_number,
                    'excerpt' => $excerpt,
                ], deepLink: 'cr7://complaints/'.$complaint->id, data: self::route($complaint));
            }
        });
    }

    /** Closed with an outcome — the person who complained is the one waiting for this. */
    public function resolved(Complaint $complaint): void
    {
        $this->guard(fn () => $this->notifications->send($complaint->user, 'complaint.resolved', [
            'ticket' => $complaint->ticket_number,
            'outcome' => self::outcome($complaint),
        ], deepLink: 'cr7://complaints/'.$complaint->id, data: self::route($complaint)));
    }

    private function tellOperators(Complaint $complaint, string $excerpt, ?int $exceptUserId): void
    {
        foreach ($this->operators() as $admin) {
            if ($admin->id === $exceptUserId) {
                continue;
            }

            $this->notifications->send($admin, 'complaint.reply_admin', [
                'ticket' => $complaint->ticket_number,
                'name' => $complaint->user?->name,
                'excerpt' => $excerpt,
            ], deepLink: '/complaints/'.$complaint->id, data: self::route($complaint));
        }
    }

    /**
     * By `users.type`, not by permission — the same call `ErrandDispatcher` makes and
     * for the same reason: the alert dock is where authority is applied, and an
     * operator who sees a notification and an empty list is a smaller failure than a
     * complaint nobody was told about.
     *
     * @return Collection<int, User>
     */
    private function operators()
    {
        return User::query()->where('type', UserType::Admin)->where('is_active', true)->get();
    }

    /**
     * What `PushDestination` routes on.
     *
     * The deep link is for the notification centre; the push carries the ids
     * alongside it so the app never parses a URL on a cold start — a malformed
     * one there is a crash on launch, not a wrong screen.
     *
     * @return array<string, string>
     */
    private static function route(Complaint $complaint): array
    {
        return ['complaint_id' => (string) $complaint->id, 'kind' => 'complaint'];
    }

    private static function excerpt(?string $text): string
    {
        return Str::limit(trim((string) $text), self::EXCERPT);
    }

    private static function outcome(Complaint $complaint): string
    {
        return match ($complaint->resolution_outcome?->value ?? (string) $complaint->resolution_outcome) {
            'refund' => 'تم استرداد المبلغ كاملًا',
            'partial_refund' => 'تم استرداد جزء من المبلغ',
            'redelivery' => 'ستتم إعادة التوصيل',
            'warning_restaurant' => 'تم إنذار المطعم',
            'warning_driver' => 'تم إنذار الكابتن',
            'rejected' => 'تمت مراجعة الشكوى ولم يثبت الادعاء',
            default => 'تمت مراجعة الشكوى وإغلاقها',
        };
    }

    /** @param callable():void $work */
    private function guard(callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            Log::warning('complaint notification failed', ['error' => $e->getMessage()]);
        }
    }
}
