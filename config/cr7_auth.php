<?php

/**
 * D-120 — the session: a short access token, and a refresh token that renews it.
 *
 * Operator, 2026-09-16: the places were being signed out «بسرعة». The API was not the
 * cause — a Sanctum token issued here never expired — but a token that never expires is
 * also a token that, once copied, works for ever. The shape now is the standard one: an
 * access token that lives an hour, renewed in the background by a refresh token that
 * lives as long as the session is used, so «انتهت الجلسة» is shown only when the renewal
 * itself is refused.
 *
 * Only the access token's lifetime is here. How long a session may sit **unused** is an
 * operator's decision and lives on `ADM-46` (`auth.session_days`, `auth.admin_session_days`
 * in `App\Support\SettingsCatalog`), because it is a trade between convenience and risk
 * that the operator, not a deploy, should make.
 *
 * `sanctum.expiration` stays null on purpose. Sanctum reads it against `created_at` for
 * **every** token, which would cut the legacy tokens the 1.0.3 and 1.0.4 apps hold at a
 * fixed age however recently they were used. An access token carries its own
 * `expires_at` instead, and a legacy one is judged by `App\Support\Auth\Sessions`.
 */
return [

    // Minutes. An hour is long enough that a place's board does not renew in the middle
    // of every rush, and short enough that a token copied out of a log is worthless by
    // the time anyone reads it.
    'access_minutes' => (int) env('AUTH_ACCESS_MINUTES', 60),

];
