<?php

/**
 * One switch for every code the platform generates.
 *
 * There is no SMS provider wired yet (`LogOtpChannel` writes the code to the
 * log). Without a fixed code nobody outside the server can log in at all, so
 * static mode is the difference between a demoable app and a dead one.
 *
 * ================== TURN THIS OFF BEFORE LAUNCH ==================
 * While `static` is true, ANY phone number logs in with the same code. That is
 * acceptable for a private staging server and is a full account takeover on a
 * public one. Flip `OTP_STATIC_MODE=false` the moment an SMS channel is bound.
 * =================================================================
 */
return [

    'static' => (bool) env('OTP_STATIC_MODE', true),

    // FR-AUTH-02 — login / register / password reset. 6 digits.
    'static_code' => (string) env('OTP_STATIC_CODE', '123456'),

    // doc 04 — pickup and delivery codes. 4 digits.
    'static_order_code' => (string) env('OTP_STATIC_ORDER_CODE', '1234'),

    /**
     * Return the code in the `POST /auth/otp/send` response.
     *
     * Only ever true alongside `static`: echoing a *random* code back to the
     * caller would hand the account to whoever asked for it. A static code is
     * already public knowledge, so echoing it just saves the tester a trip to
     * the log — and lets the app print «كود التجربة» instead of the customer
     * guessing.
     */
    'echo_in_response' => (bool) env('OTP_ECHO_IN_RESPONSE', true),
];
