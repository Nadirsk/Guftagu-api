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

];
