<?php

/**
 * D-106 — the verification-code switch is not here any more.
 *
 * `OTP_STATIC_MODE`, `OTP_STATIC_CODE`, `OTP_STATIC_ORDER_CODE` and
 * `OTP_ECHO_IN_RESPONSE` are gone. What they decided now lives on `ADM-46`,
 * «التحقق والرسائل», as `otp.mode` (`sms` · `test`), `otp.test_code` and
 * `auth.register_requires_otp` — see `App\Support\SettingsCatalog` and
 * `App\Support\Otp`. Pickup and delivery codes are random, always (D-108).
 *
 * `.env` keeps only the gateway: `OTP_CHANNEL` (`config/services.php`) and the
 * `SMS_MISR_*` credentials. An empty array is kept here so nothing that still
 * calls `config('otp')` fails; nothing in `app/` does.
 */
return [];
