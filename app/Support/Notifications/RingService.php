<?php

namespace App\Support\Notifications;

use App\Models\DeviceToken;
use App\Models\Order;
use App\Models\User;
use App\Support\SettingsCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * D-100 — «رنه في ال application يعني حتي لو قافل التطبيق قافل التليفون يرن كانه بيرن عليه.
 * بيرن ‪30‬ ثانيه لو المكان قبل او رفض الرنه بتروح او لو معملش حاجه بيقعد دقيقه ويرن تاني
 * … تلاته بس في حاله ان مفيش رد».
 *
 * The schedule lives on the order — `ring_attempts`, `ring_next_at` — and is the server's,
 * not the phone's: a place with two phones, or one phone and the web board, has one
 * schedule, and an answer from any of them stops all of them. The phone only plays what it
 * is sent and stops when it is told to.
 *
 * Ring 1 goes out the moment the order is committed; `cr7:tick` sends the rest. Every move
 * out of `pending` — accept, reject, cancel, the accept timeout — clears the schedule and
 * sends `order.ring_stop`.
 *
 * Nothing here writes a `notifications` row: the feed carries the order once, as
 * `order.new`, not once per ring.
 */
class RingService
{
    public function __construct(private readonly FcmClient $fcm) {}

    public static function enabled(): bool
    {
        return SettingsCatalog::bool('ring.enabled');
    }

    /** Ring the place for this order and schedule the next ring, or none after the last. */
    public function ring(Order $order, int $attempt, ?int $notificationId = null, bool $reschedule = true): void
    {
        $owner = $order->restaurant?->owner;

        if ($owner === null || $order->isDirect()) {
            return;
        }

        $duration = SettingsCatalog::int('ring.duration_seconds');
        $gap = SettingsCatalog::int('ring.gap_seconds');
        $max = SettingsCatalog::int('ring.max_attempts');

        // «إعادة تنبيه المطعم» rings once more without touching the schedule.
        if ($reschedule) {
            $order->forceFill([
                'ring_attempts' => $attempt,
                // Counted from the **end** of this ring: «بيرن ‪30‬ ثانيه … بيقعد دقيقه ويرن تاني».
                'ring_next_at' => $attempt < $max ? now()->addSeconds($duration + $gap) : null,
            ])->saveQuietly();
        }

        $locale = $owner->locale ?: 'ar';
        $copy = NotificationCopy::copy('order.ring', $locale, self::context($order));

        $data = [
            'type' => 'order.ring',
            'order_id' => (string) $order->id,
            'order_number' => (string) $order->order_number,
            'attempt' => (string) $attempt,
            'max_attempts' => (string) $max,
            'duration_seconds' => (string) $duration,
            'title' => $copy['title'],
            'body' => $copy['body'],
            'deep_link' => '/orders/'.$order->id,
        ];

        if ($notificationId !== null) {
            $data['notification_id'] = (string) $notificationId;
        }

        $this->deliver($owner, 'ring', $copy, $data, $duration + $gap, $notificationId);
    }

    /** Stop the ringing on every device the owner has, and clear the schedule. */
    public function stop(Order $order): void
    {
        if ((int) $order->ring_attempts === 0 && $order->ring_next_at === null) {
            return;
        }

        $order->forceFill(['ring_next_at' => null])->saveQuietly();

        $owner = $order->restaurant?->owner;

        if ($owner === null) {
            return;
        }

        $this->deliver($owner, 'ring_stop', ['title' => '', 'body' => ''], [
            'type' => 'order.ring_stop',
            'order_id' => (string) $order->id,
        ], 60, null);
    }

    /** @return array<string, mixed> */
    public static function context(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'items' => $order->items_count,
            'total' => number_format((float) $order->restaurant_amount, 2).' ج.م',
            'payment' => $order->payment_method?->value === 'cash'
                ? ['ar' => 'نقدًا', 'en' => 'cash']
                : ['ar' => 'مدفوع إلكترونيًا', 'en' => 'paid online'],
        ];
    }

    /**
     * @param  array{title: string, body: string}  $copy
     * @param  array<string, string>  $data
     */
    private function deliver(User $owner, string $kind, array $copy, array $data, int $ttl, ?int $notificationId): void
    {
        $send = function () use ($owner, $kind, $copy, $data, $ttl, $notificationId): void {
            try {
                // Only a device that can ring is rung (a browser, or a phone on the build
                // before the ring, gets the ordinary push from `OrderNotifications`), and an
                // iOS alert cannot be cancelled early, so it is never sent a stop.
                $platform = static fn (DeviceToken $device): string => $device->platform instanceof \BackedEnum
                    ? (string) $device->platform->value
                    : (string) $device->platform;

                $devices = $owner->deviceTokens()->get()
                    ->filter(fn (DeviceToken $device) => $device->rings())
                    ->filter(fn (DeviceToken $device) => $kind === 'ring' || $platform($device) !== 'ios')
                    ->values()
                    ->all();

                if ($devices === []) {
                    return;
                }

                $results = $this->fcm->send(array_map(fn (DeviceToken $device) => [
                    'token' => $device->token,
                    'title' => $copy['title'],
                    'body' => $copy['body'],
                    'data' => $data,
                    'kind' => $kind,
                    'platform' => $platform($device),
                    'ttl' => $ttl,
                ], $devices));

                $dead = [];
                $rows = [];

                foreach ($devices as $index => $device) {
                    $result = $results[$index] ?? ['ok' => false, 'error' => 'no_result', 'unregistered' => false];

                    if ($result['unregistered']) {
                        $dead[] = $device->id;
                    }

                    if ($notificationId !== null) {
                        $rows[] = [
                            'notification_id' => $notificationId,
                            'channel' => 'push',
                            'device_token' => mb_substr($device->token, 0, 250),
                            'status' => $result['ok'] ? 'sent' : 'failed',
                            'error' => $result['error'],
                            'sent_at' => $result['ok'] ? now() : null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('notification_deliveries')->insert($rows);
                }

                if ($dead !== []) {
                    DeviceToken::query()->whereIn('id', $dead)->delete();
                }
            } catch (Throwable $e) {
                Log::warning('ring delivery failed', ['order_id' => $data['order_id'] ?? null, 'kind' => $kind, 'error' => $e->getMessage()]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->later($send));

            return;
        }

        $this->later($send);
    }

    private function later(callable $send): void
    {
        if (app()->runningInConsole()) {
            $send();

            return;
        }

        dispatch($send)->afterResponse();
    }
}
