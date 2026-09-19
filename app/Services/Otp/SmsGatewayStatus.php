<?php

namespace App\Services\Otp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * D-106 — the gateway, as the server sees it, for the panel that decides whether to use it.
 *
 * `otp.mode = sms` on `ADM-46` is only true if a message actually leaves. Every fault on
 * this path is silent — SMS Misr answers HTTP 200 on failure, and its **test** environment
 * accepts a message and delivers nothing — so the operator flipping the switch deserves to
 * see, beside it, whether anything would arrive. The credentials themselves never leave the
 * server; only whether each one is set.
 */
class SmsGatewayStatus
{
    /** @return array<string, mixed> */
    public function report(): array
    {
        $channel = (string) config('services.otp_channel', 'log');

        if ($channel === 'zadx') {
            return $this->zadx();
        }

        $sms = (array) config('services.sms_misr', []);
        $live = (string) ($sms['environment'] ?? '2') === '1';
        $credentials = filled($sms['username'] ?? null) && filled($sms['password'] ?? null);
        $sender = filled($sms['sender'] ?? null);
        $arabicSafe = (string) ($sms['language'] ?? '2') !== '1';

        $delivers = $channel === 'sms_misr' && $live && $credentials && $sender && $arabicSafe;

        return [
            'channel' => $channel,
            'environment' => $live ? 'live' : 'test',
            'credentials_configured' => $credentials,
            'sender_configured' => $sender,
            'balance' => $channel === 'sms_misr' && $credentials ? $this->balance($sms) : null,
            'delivers' => $delivers,
            'note' => $this->note($channel, $live, $credentials, $sender, $arabicSafe),
        ];
    }

    /**
     * ZADX has one environment — every send is real and spends a credit — so `environment`
     * is always `live` and `delivers` is simply «the key, the secret and the sender are set».
     * `balance` is the plan's remaining credits, read from the free `/sms/balance` call.
     *
     * @return array<string, mixed>
     */
    private function zadx(): array
    {
        $credentials = ZadxChannel::configured();
        $sender = filled(config('services.zadx.sender'));

        return [
            'channel' => 'zadx',
            'provider' => 'zadx',
            'environment' => 'live',
            'credentials_configured' => $credentials,
            'sender_configured' => $sender,
            'balance' => $credentials ? $this->zadxCredits() : null,
            'delivers' => $credentials && $sender,
            'note' => match (true) {
                ! $credentials => 'مفتاح ZADX أو سرّه ناقص في ملف ‪.env‬ (ZADX_API_KEY / ZADX_API_SECRET).',
                ! $sender => 'لا يوجد Sender ID في ملف ‪.env‬ (ZADX_SENDER_ID).',
                default => 'البوابة تعمل وترسل رسائل حقيقية عبر ZADX. الرصيد بوحدة الرسائل (credits).',
            },
        ];
    }

    private function zadxCredits(): ?float
    {
        return Cache::remember('zadx:balance', 300, function (): ?float {
            $balance = (new ZadxChannel)->balance();
            $credits = $balance['remaining_credits'] ?? null;

            return is_numeric($credits) ? (float) $credits : null;
        });
    }

    private function note(string $channel, bool $live, bool $credentials, bool $sender, bool $arabicSafe): string
    {
        return match (true) {
            $channel !== 'sms_misr' => 'بوابة الرسائل غير مفعّلة على الخادم (OTP_CHANNEL='.$channel.') — لن تصل أي رسالة. فعّلها من ملف ‪.env‬.',
            ! $credentials => 'بيانات حساب SMS Misr ناقصة في ملف ‪.env‬.',
            ! $sender => 'لا يوجد Sender ID في ملف ‪.env‬ (SMS_MISR_SENDER).',
            ! $arabicSafe => 'SMS_MISR_LANGUAGE=1 لا يحمل الحروف العربية — استخدم ‪2‬.',
            ! $live => 'بيئة الاختبار في SMS Misr تقبل الرسالة ولا توصلها. ضع الـ Sender ID المعتمد واجعل SMS_MISR_ENVIRONMENT=1 ثم نفّذ config:cache.',
            default => 'البوابة تعمل وترسل رسائل حقيقية.',
        };
    }

    /** SMS Misr's balance lookup is free; cached so the panel does not call it on every open. */
    private function balance(array $sms): ?float
    {
        return Cache::remember('sms_misr:balance', 300, function () use ($sms): ?float {
            try {
                $response = Http::asForm()->timeout(6)->post((string) ($sms['balance_url'] ?? ''), [
                    'environment' => (string) ($sms['environment'] ?? '2'),
                    'username' => (string) $sms['username'],
                    'password' => (string) $sms['password'],
                ]);
            } catch (Throwable) {
                return null;
            }

            $body = $response->json();

            if (! is_array($body)) {
                return null;
            }

            foreach (['balance', 'Balance', 'points', 'Points'] as $key) {
                if (isset($body[$key]) && is_numeric($body[$key])) {
                    return (float) $body[$key];
                }
            }

            return null;
        });
    }
}
