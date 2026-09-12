<?php

namespace App\Console\Commands;

use App\Services\Otp\LogOtpChannel;
use App\Services\Otp\OtpChannel;
use App\Services\Otp\SmsMisrChannel;
use App\Support\Otp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Is the SMS gateway actually able to send, and is sign-in wired to it?
 *
 * Every fault in this path is silent by construction. SMS Misr answers HTTP 200
 * with the error in the body, `POST /auth/otp/send` answers 200 either way, and
 * the customer's only signal is an SMS that never arrives. Running this before
 * flipping `OTP_STATIC_MODE=false` is the difference between finding out here
 * and finding out from a customer who cannot sign in.
 *
 * With no argument it only reads — config, binding and the static-mode pairing.
 * Given a phone number it sends one real message, which costs points; it asks
 * first, and `--force` skips the question for a scripted run.
 */
class SmsDoctorCommand extends Command
{
    protected $signature = 'cr7:sms-doctor
        {phone? : send one real verification message to this number}
        {--force : send without asking}';

    protected $description = 'Check the SMS gateway configuration, and optionally send one test message';

    public function handle(): int
    {
        $this->settings();

        $ok = $this->verdict();

        $phone = $this->argument('phone');

        if ($phone === null) {
            $this->newLine();
            $this->line('pass a phone number to send one real message: php artisan cr7:sms-doctor 01012345678');

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        return $this->send((string) $phone);
    }

    private function settings(): void
    {
        $sms = config('services.sms_misr');
        $otpMode = ($sms['mode'] ?? 'sms') === 'otp';

        $this->info('configuration');
        $this->table(['key', 'value'], [
            ['OTP_CHANNEL', config('services.otp_channel')],
            ['bound channel', $this->channel()],
            ['OTP_STATIC_MODE', Otp::isStatic() ? 'true' : 'false'],
            ['mode', $sms['mode'] ?? 'sms'],
            ['endpoint', $otpMode ? ($sms['otp_url'] ?? '') : ($sms['url'] ?? '')],
            ['environment', ($sms['environment'] ?? '').($sms['environment'] === '1' ? '  (live)' : '  (test)')],
            ['language', ($sms['language'] ?? '').'  '.$this->languageName((string) ($sms['language'] ?? ''))],
            ['username', $this->present($sms['username'] ?? null)],
            ['password', $this->present($sms['password'] ?? null)],
            ['sender', $sms['sender'] ?: '— MISSING —'],
            ['template', $otpMode ? ($sms['template'] ?: '— MISSING —') : 'n/a in sms mode'],
        ]);
    }

    /** Report every fault at once — a list of one is how a second one gets missed. */
    private function verdict(): bool
    {
        $sms = config('services.sms_misr');
        $faults = [];

        if (config('services.otp_channel') !== 'sms_misr') {
            $faults[] = 'OTP_CHANNEL is not sms_misr, so nothing here is used — codes go to storage/logs.';
        }

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

        // The two pairings that look like a working server and are not.
        if (Otp::isStatic() && app()->environment('production')) {
            $faults[] = 'OTP_STATIC_MODE=true in production — every phone signs in with the same code.';
        }

        if (! Otp::isStatic() && $this->channel() === LogOtpChannel::class) {
            $faults[] = 'Static mode is off and the channel is the log — nobody can sign in at all.';
        }

        $this->newLine();

        foreach ($faults as $fault) {
            $this->error('✗ '.$fault);
        }

        if ($faults === []) {
            $this->info('✓ configuration is complete');
        }

        return $faults === [];
    }

    private function send(string $phone): int
    {
        $live = (string) config('services.sms_misr.environment') === '1';

        $this->newLine();
        $this->warn(sprintf(
            'About to send one %s message to %s via %s.%s',
            $live ? 'LIVE' : 'test',
            $phone,
            $this->channel() === SmsMisrChannel::class ? 'SMS Misr' : 'the log channel',
            $live ? ' This spends points from the account balance.' : '',
        ));

        if (! $this->option('force') && ! $this->confirm('Send it?', false)) {
            $this->line('nothing sent');

            return self::SUCCESS;
        }

        // A fixed, obviously-not-real code: the log keeps this line, and a real
        // one sitting in a log file is a credential.
        $code = '000000';

        try {
            app(OtpChannel::class)->send($phone, $code);
        } catch (\Throwable $e) {
            $this->error('send failed: '.$e->getMessage());
            $this->line('the gateway\'s own code and its meaning are in storage/logs/laravel.log under sms_misr.send_failed');

            return self::FAILURE;
        }

        $this->info('✓ the gateway accepted it');
        $this->line($live
            ? 'The phone should have it. If it does not arrive, the send succeeded and delivery did not — that is an SMS Misr routing question.'
            : 'environment=2 is test: SMS Misr validated the request and did NOT deliver it. Set SMS_MISR_ENVIRONMENT=1 for a real send.');

        Log::info('sms_misr.doctor', ['phone' => $phone]);

        return self::SUCCESS;
    }

    /** @return class-string */
    private function channel(): string
    {
        return app(OtpChannel::class)::class;
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

    private function present(?string $value): string
    {
        return blank($value) ? '— MISSING —' : 'set ('.strlen($value).' chars)';
    }
}
