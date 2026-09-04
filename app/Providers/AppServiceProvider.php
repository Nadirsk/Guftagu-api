<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiters();
    }

    /**
     * docs/01 §6 — "login 5/min/IP … admin 300/min. Redis-backed, returns 429 with Retry-After."
     */
    protected function configureRateLimiters(): void
    {
        RateLimiter::for('admin-login', function (Request $request) {
            // Keyed on IP *and* the submitted email so one attacker cannot lock out an
            // entire office NAT, and one email cannot be sprayed from many IPs.
            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('email:'.strtolower((string) $request->input('email'))),
            ];
        });

        RateLimiter::for('admin-api', function (Request $request) {
            return Limit::perMinute(300)->by(
                $request->user()?->id
                    ? 'admin:'.$request->user()->id
                    : 'ip:'.$request->ip()
            );
        });

        RateLimiter::for('admin-mfa', function (Request $request) {
            // Tighter than login: a 6-digit OTP is brute-forceable at higher rates.
            return Limit::perMinute(10)->by('ip:'.$request->ip());
        });

        RateLimiter::for('admin-translate', function (Request $request) {
            // The upstream translate endpoint is unauthenticated and unofficial — keeping
            // this well under admin-api's 300/min limits how hard one admin can hit it.
            return Limit::perMinute(30)->by('admin:'.$request->user()?->id);
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });
    }
}
