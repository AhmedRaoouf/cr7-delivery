<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Models\User;
use App\Support\Notifications\FcmClient;
use Illuminate\Console\Command;

/**
 * Why no notification arrived — answered on the host, in one command.
 *
 * A push in this application fails silently by design: `NotificationDispatcher`
 * writes the in-app row first and swallows every delivery error, because a dead
 * FCM project must degrade to «the notification centre works», never to «the
 * order machine is down». That is the right trade and it costs exactly one
 * thing — from the outside, «FCM is not configured», «this account has no
 * phone registered» and «the token is stale» all look identical: nothing
 * happens.
 *
 * So they are printed here instead. The three failures are stacked in the order
 * they break, because the first one that is red makes the rest moot.
 */
class PushDoctorCommand extends Command
{
    protected $signature = 'cr7:push-doctor
        {--to= : user id to send a real test push to}';

    protected $description = 'Report why push notifications are or are not being delivered';

    public function handle(FcmClient $fcm): int
    {
        $this->newLine();
        $this->line('<options=bold>1 · FCM credentials</>');

        $path = config('services.firebase.credentials');
        $configured = $fcm->isConfigured();

        $this->line('   path       '.($path ?: '<not set>'));
        $this->line('   exists     '.$this->yesNo(is_string($path) && file_exists($path)));
        $this->line('   readable   '.$this->yesNo($configured));

        if (! $configured) {
            $this->newLine();
            $this->error('   FCM is not configured. Every push returns `fcm_not_configured` and is dropped.');
            $this->line('   Put the Firebase service-account JSON at the path above — outside `public/`');
            $this->line('   and outside `storage/app/public/` — then `chmod 600` it. It is a real secret:');
            $this->line('   anyone holding it can send a notification to every phone on the platform.');
        }

        $this->newLine();
        $this->line('<options=bold>2 · registered devices</>');

        // `->get()` on the model still applies the casts, so `app` and `platform` come
        // back as `DeviceApp` and `DevicePlatform` — and `table()` refuses anything that
        // is not a scalar or `Stringable`. This is the third time an enum-cast column has
        // bitten on this path: `DeviceController::store()` answered 500 for it, and the
        // `ready_source === 'timer'` comparison was silently false for it. `toBase()`
        // returns plain rows and casts nothing.
        $rows = DeviceToken::query()
            ->selectRaw('app, platform, count(*) as total, max(last_used_at) as newest')
            ->groupBy('app', 'platform')
            ->toBase()
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('   none. No phone on the platform can receive anything.');
            $this->line('   A device registers on sign-in, so this being empty after a real login');
            $this->line('   means `POST /devices` is failing — check `telescope` for a 422 or 500.');
        } else {
            $this->table(
                ['app', 'platform', 'tokens', 'newest'],
                $rows->map(fn ($r) => [
                    (string) $r->app,
                    (string) $r->platform,
                    (int) $r->total,
                    (string) ($r->newest ?? '—'),
                ])->all(),
            );
        }

        $this->newLine();
        $this->line('<options=bold>3 · test send</>');

        $userId = $this->option('to');

        if ($userId === null) {
            $this->line('   skipped — pass --to=<user id> to send one.');

            return self::SUCCESS;
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            $this->error('   no user '.$userId);

            return self::FAILURE;
        }

        $tokens = $user->deviceTokens()->pluck('token', 'device_id');

        if ($tokens->isEmpty()) {
            $this->error('   '.$user->name.' has no registered device.');

            return self::FAILURE;
        }

        $results = $fcm->send($tokens->map(fn (string $token) => [
            'token' => $token,
            'title' => 'اختبار الإشعارات',
            'body' => 'وصلك هذا الإشعار — الإعداد سليم',
            'data' => ['type' => 'diagnostic'],
        ])->values()->all());

        foreach ($results as $index => $result) {
            $deviceId = $tokens->keys()[$index] ?? '?';
            $this->line(sprintf(
                '   %-24s %s%s',
                $deviceId,
                $result['ok'] ? '<info>delivered</info>' : '<error>failed</error>',
                $result['ok'] ? '' : '  '.($result['error'] ?? 'unknown').($result['unregistered'] ? ' (stale token)' : ''),
            ));
        }

        return self::SUCCESS;
    }

    private function yesNo(bool $value): string
    {
        return $value ? '<info>yes</info>' : '<error>no</error>';
    }
}
