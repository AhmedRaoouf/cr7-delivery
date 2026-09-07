<?php

namespace App\Support\Notifications;

use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One notification, from a `type` and a recipient to a row in the centre and a
 * push on their phone.
 *
 * **Why it is not a queued job.** `app/Jobs` does not exist in this application
 * and there is no worker on the host — DEPLOY.md §9 says so, having been
 * corrected once already for claiming otherwise. A queued push here would sit in
 * the `jobs` table until somebody noticed. So the send is dispatched
 * `afterResponse()`: the same PHP process, after the response has been flushed,
 * which costs the request nothing and needs no worker. Under the console and in
 * tests there is no response to be after, so it runs inline.
 *
 * **The in-app row is written first, and always.** It is inside the caller's
 * transaction and is the record; the push is a best-effort copy of it on a
 * device that may be off, uninstalled or out of battery. A customer who missed
 * the push still finds «تم تأكيد طلبك» in `CUS-43`. This ordering is the reason
 * a dead FCM project degrades to «the notification centre works» rather than to
 * «the order machine is down».
 */
class NotificationDispatcher
{
    public function __construct(private readonly FcmClient $fcm) {}

    /**
     * @param  array<string, string|int|float|null>  $context  values for the copy's `{placeholders}`
     * @param  array<string, string>  $data  the push payload the app routes on
     */
    public function send(
        ?User $recipient,
        string $type,
        array $context = [],
        ?string $deepLink = null,
        ?int $orderId = null,
        array $data = [],
        string $channel = 'default',
    ): ?Notification {
        if ($recipient === null) {
            return null;
        }

        $locale = $recipient->locale ?: 'ar';
        $copy = NotificationCopy::copy($type, $locale, $context);

        // The payload holds **both** locales, not the rendered one. The centre is
        // read long after the push was sent, and a customer who switched the app
        // to English in between should not meet a row frozen in the language they
        // left. `NotificationRemoteDataSource` picks the side it wants.
        $arabic = NotificationCopy::copy($type, 'ar', $context);
        $english = NotificationCopy::copy($type, 'en', $context);

        $notification = Notification::create([
            'user_id' => $recipient->id,
            'type' => $type,
            'title_key' => $type,
            'payload' => [
                'title_ar' => $arabic['title'],
                'body_ar' => $arabic['body'],
                'title_en' => $english['title'],
                'body_en' => $english['body'],
            ],
            'deep_link' => $deepLink,
            'order_id' => $orderId,
        ]);

        /*
         * The copy rides in `data` as well as in the `notification` block.
         *
         * Android draws a `notification` block itself and hands the app only
         * `data`; a message that ever arrives data-only — a future silent push,
         * a background isolate that wants to decide what to draw — would
         * otherwise have a routing payload and no words in it. Two short
         * strings, and the app never has to guess.
         */
        $this->push(
            $recipient,
            $notification,
            $copy,
            $data + [
                'type' => $type,
                'title' => $copy['title'],
                'body' => $copy['body'],
                'notification_id' => (string) $notification->id,
            ],
            $channel,
        );

        return $notification;
    }

    /**
     * @param  array{title: string, body: string}  $copy
     * @param  array<string, string>  $data
     */
    private function push(
        User $recipient,
        Notification $notification,
        array $copy,
        array $data,
        string $channel,
    ): void {
        if ($copy['title'] === '') {
            return;
        }

        $send = function () use ($recipient, $notification, $copy, $data, $channel): void {
            try {
                $this->deliver($recipient, $notification, $copy, $data, $channel);
            } catch (Throwable $e) {
                // A push is never worth failing anything for. The row is already
                // written and the centre already has it.
                Log::warning('push delivery failed', [
                    'notification_id' => $notification->id,
                    'error' => $e->getMessage(),
                ]);
            }
        };

        // `afterResponse` inside a transaction would fire on a row that may still
        // roll back, so the commit comes first and the response after that.
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

    /**
     * @param  array{title: string, body: string}  $copy
     * @param  array<string, string>  $data
     */
    private function deliver(
        User $recipient,
        Notification $notification,
        array $copy,
        array $data,
        string $channel,
    ): void {
        /** @var array<int, DeviceToken> $devices */
        $devices = $recipient->deviceTokens()->get()->all();

        if ($devices === []) {
            return;
        }

        $results = $this->fcm->send(array_map(
            fn (DeviceToken $device) => [
                'token' => $device->token,
                'title' => $copy['title'],
                'body' => $copy['body'],
                'data' => array_map(fn ($value) => (string) $value, $data),
                'channel' => $channel,
            ],
            $devices,
        ));

        $rows = [];
        $dead = [];

        foreach ($devices as $index => $device) {
            $result = $results[$index] ?? ['ok' => false, 'error' => 'no_result', 'unregistered' => false];

            $rows[] = [
                'notification_id' => $notification->id,
                'channel' => 'push',
                'device_token' => mb_substr($device->token, 0, 250),
                'status' => $result['ok'] ? 'sent' : 'failed',
                'error' => $result['error'],
                'sent_at' => $result['ok'] ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($result['unregistered']) {
                $dead[] = $device->id;
            }
        }

        DB::table('notification_deliveries')->insert($rows);

        // An uninstalled app's token never comes back to life, and left in place
        // it is one guaranteed failed request on every future notification to
        // that account, for as long as the account exists.
        if ($dead !== []) {
            DeviceToken::query()->whereIn('id', $dead)->delete();
        }
    }
}
