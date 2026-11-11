<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin MFA
    |--------------------------------------------------------------------------
    */

    'admin_mfa' => [

        /*
         * A fixed OTP for local development, so click-testing does not mean digging the
         * code out of storage/logs/laravel.log on every sign-in.
         *
         * This is IGNORED unless APP_ENV=local. The environment check lives in
         * AdminAuthService and does not consult this value first, so setting it on a
         * deployed box does nothing — see AdminAuthTest::a_static_otp_is_ignored_outside_local.
         *
         * Must be exactly six digits. Anything else is discarded and a random code is used.
         */
        'static_otp' => env('ADMIN_MFA_STATIC_OTP'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile OTP (D.1a)
    |--------------------------------------------------------------------------
    */

    'user_otp' => [

        // Same idea as admin_mfa.static_otp, and the same rule: ignored unless
        // APP_ENV=local. See OtpService::nextOtp.
        'static_otp' => env('USER_OTP_STATIC_OTP'),

        // Fixed-code test accounts, "phone:code" pairs separated by commas. Unlike
        // static_otp above this is honoured in EVERY environment, live included, and
        // only for the listed numbers; the SMS still goes out, carrying the fixed code. Set the env var
        // to an empty string to switch it off.
        'static_numbers' => env('USER_OTP_STATIC_NUMBERS', '9326431979:123456'),

        'ttl_minutes' => 5,
        'max_attempts' => 5,

        // docs/03 §16 — "OTP send 3/hour/phone · 10/day/IP". Env-overridable so a
        // dev machine can resend freely without the shipped defaults moving.
        'send_per_hour' => (int) env('USER_OTP_SEND_PER_HOUR', 3),
        'send_per_day_ip' => (int) env('USER_OTP_SEND_PER_DAY_IP', 10),

    ],

    /*
    |--------------------------------------------------------------------------
    | Admin panel URL
    |--------------------------------------------------------------------------
    |
    | GFT-127 — where the panel-user welcome email (AdminWelcomeMail) points its
    | "Open admin panel" button. Falls back to APP_URL so a checkout with no
    | FRONTEND_URL set still renders a valid link instead of an empty href.
    */

    /*
     * Diamond → coin exchange (the app's "Points" screen). How many diamonds buy one coin;
     * 5 diamonds = 1 coin. Keep EXCHANGE_RATE in mobile ExchangeScreen.tsx the same.
     */
    'diamonds_per_coin' => (int) env('DIAMONDS_PER_COIN', 5),

    'frontend_url' => rtrim(env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/'),

];
