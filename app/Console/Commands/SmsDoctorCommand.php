<?php

namespace App\Console\Commands;

use App\Services\Otp\LogOtpChannel;
use App\Services\Otp\OtpChannel;
use App\Services\Otp\SmsMisrChannel;
use App\Support\Otp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Can SMS Misr send for us, and is sign-in using it yet?
 *
 * Those are two separate questions and this answers them separately. Every
 * fault on this path is silent — SMS Misr answers HTTP 200 with the error in
 * the body, and `POST /auth/otp/send` answers 200 whatever happens underneath —
 * so the gateway has to be proven on its own before sign-in is pointed at it.
 *
 * That is why a test send always goes through `SmsMisrChannel` directly, never
 * through whatever `OtpChannel` is bound. The gateway can be exercised on a live
 * server while customers still sign in exactly as before; flipping
 * `OTP_CHANNEL` is the last step, not a precondition for testing.
 *
 * With no argument it only reads, plus the balance lookup, which spends nothing.
 * Given a phone number it sends one message. `--sender` tries a different sender
 * token for that one run — SMS Misr issues a test token and a production token
 * separately, and the only way to learn which one an environment accepts is to
 * try it.
 */
class SmsDoctorCommand extends Command
{
    protected $signature = 'cr7:sms-doctor
        {phone? : send one verification message to this number}
        {--sender= : use this sender token for this run instead of SMS_MISR_SENDER}
        {--force : send without asking}';

    protected $description = 'Check the SMS Misr gateway, and optionally send one test message';

    public function handle(): int
    {
        if (filled($this->option('sender'))) {
            config(['services.sms_misr.sender' => (string) $this->option('sender')]);
        }

        $this->settings();

        $ready = $this->gatewayVerdict();

        $this->balance();

        $this->signInStatus();

        $phone = $this->argument('phone');

        if ($phone === null) {
            $this->newLine();
            $this->line('to send one message: php artisan cr7:sms-doctor 01012345678');

            return $ready ? self::SUCCESS : self::FAILURE;
        }

        if (! $ready) {
            $this->newLine();
            $this->error('not sending — fix the gateway faults above first');

            return self::FAILURE;
        }

        return $this->send((string) $phone);
    }

    private function settings(): void
    {
        $sms = config('services.sms_misr');
        $otpMode = ($sms['mode'] ?? 'sms') === 'otp';

        $this->info('SMS Misr');
        $this->table(['key', 'value'], [
            ['mode', $sms['mode'] ?? 'sms'],
            ['endpoint', $otpMode ? ($sms['otp_url'] ?? '') : ($sms['url'] ?? '')],
            ['environment', ($sms['environment'] ?? '').((string) ($sms['environment'] ?? '') === '1' ? '  (live)' : '  (test)')],
            ['language', ($sms['language'] ?? '').'  '.$this->languageName((string) ($sms['language'] ?? ''))],
            ['username', $this->masked($sms['username'] ?? null)],
            ['password', $this->masked($sms['password'] ?? null)],
            ['sender', $this->masked($sms['sender'] ?? null).($this->option('sender') ? '  (from --sender)' : '')],
            ['template', $otpMode ? $this->masked($sms['template'] ?? null) : 'n/a in sms mode'],
        ]);
    }

    /** Only what stops SMS Misr itself from accepting a message. */
    private function gatewayVerdict(): bool
    {
        $sms = config('services.sms_misr');
        $faults = [];

        foreach (['username', 'password', 'sender'] as $key) {
            if (blank($sms[$key] ?? null)) {
                $faults[] = 'SMS_MISR_'.strtoupper($key).' is empty.';
            }
        }

        if (($sms['mode'] ?? 'sms') === 'otp' && blank($sms['template'] ?? null)) {
            $faults[] = 'SMS_MISR_MODE=otp needs SMS_MISR_TEMPLATE, and it is empty.';
        }

        if ((string) ($sms['language'] ?? '') === '1') {
            $faults[] = 'SMS_MISR_LANGUAGE=1 is English/GSM-7 and cannot carry an Arabic letter. Use 2, or 3 for mixed text.';
        }

        foreach ($faults as $fault) {
            $this->error('✗ '.$fault);
        }

        if ($faults === []) {
            $this->info('✓ gateway configuration is complete');
        }

        return $faults === [];
    }

    /**
     * `/api/Balance/` is the only call that proves a username and password
     * without an approved sender and without spending anything.
     *
     * POST, form-encoded, like the other two endpoints. SMS Misr's own page
     * labels it `GET /api/Balance/`, and on 2026-09-13 the production server's
     * GET came back `405 — The requested resource does not support http method
     * 'GET'`.
     */
    private function balance(): void
    {
        $sms = config('services.sms_misr');

        if (blank($sms['username'] ?? null) || blank($sms['password'] ?? null)) {
            return;
        }

        $this->newLine();
        $this->info('balance lookup');

        try {
            $response = Http::asForm()->timeout(20)->post((string) $sms['balance_url'], [
                'environment' => (string) $sms['environment'],
                'username' => (string) $sms['username'],
                'password' => (string) $sms['password'],
            ]);
        } catch (\Throwable $e) {
            $this->error('could not reach SMS Misr: '.$e->getMessage());

            return;
        }

        $body = is_array($response->json()) ? $response->json() : [];

        if ((string) ($body['code'] ?? '') === '1903') {
            $this->error('✗ 1903 — SMS Misr rejected the username or password');

            return;
        }

        $this->line('HTTP '.$response->status().'  '.$response->body());
    }

    /** Whether customers are using the gateway yet. Informational — never a fault here. */
    private function signInStatus(): void
    {
        $bound = app(OtpChannel::class)::class;

        $this->newLine();
        $this->info('sign-in');
        $this->table(['key', 'value'], [
            ['OTP_CHANNEL', (string) config('services.otp_channel')],
            ['bound channel', class_basename($bound)],
            ['OTP_STATIC_MODE', Otp::isStatic() ? 'true' : 'false'],
        ]);

        if ($bound === LogOtpChannel::class && Otp::isStatic()) {
            $this->line('customers still sign in with the static code — SMS Misr is not in their path yet');
        }

        if (Otp::isStatic() && app()->environment('production')) {
            $this->warn('! OTP_STATIC_MODE=true in production — every phone signs in with the same code');
        }

        if (! Otp::isStatic() && $bound === LogOtpChannel::class) {
            $this->error('✗ static mode is off and the channel is the log — NOBODY CAN SIGN IN');
        }
    }

    private function send(string $phone): int
    {
        $live = (string) config('services.sms_misr.environment') === '1';

        $this->newLine();
        $this->warn(sprintf(
            'About to send one %s message to %s through SMS Misr.%s',
            $live ? 'LIVE' : 'test-environment',
            $phone,
            $live ? ' This spends points.' : '',
        ));

        if (! $this->option('force') && ! $this->confirm('Send it?', false)) {
            $this->line('nothing sent');

            return self::SUCCESS;
        }

        // Fixed and obviously not real: the log keeps this, and a real code in a
        // log file is a credential.
        try {
            (new SmsMisrChannel)->send($phone, '000000');
        } catch (\Throwable $e) {
            $this->error('✗ send failed: '.$e->getMessage());
            $this->line('SMS Misr\'s code and its meaning: storage/logs/laravel.log, look for sms_misr.send_failed');

            return self::FAILURE;
        }

        $this->info('✓ SMS Misr accepted it (1901)');
        $this->line($live
            ? 'Check the phone. Accepted but not received is a delivery question for SMS Misr, not a fault here.'
            : 'Test environment: SMS Misr validated the request. Whether it is also delivered depends on the account — check the phone.');

        Log::info('sms_misr.doctor', ['phone' => $phone, 'environment' => $live ? 'live' : 'test']);

        return self::SUCCESS;
    }

    private function languageName(string $value): string
    {
        return match ($value) {
            '1' => '(English — cannot carry Arabic)',
            '2' => '(Arabic)',
            '3' => '(Unicode)',
            default => '(unknown)',
        };
    }

    /** A token is a credential. Enough to tell two apart, never enough to use one. */
    private function masked(?string $value): string
    {
        if (blank($value)) {
            return '— MISSING —';
        }

        return substr($value, 0, 6).'…  ('.strlen($value).' chars)';
    }
}
