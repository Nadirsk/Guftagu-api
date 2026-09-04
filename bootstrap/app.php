<?php

use App\Domain\Access\Exceptions\PermissionException;
use App\Domain\Access\Exceptions\ScopeException;
use App\Domain\Agency\AgencyException;
use App\Domain\Cms\CmsException;
use App\Domain\Economy\EconomyException;
use App\Domain\Events\EventException;
use App\Domain\Moderation\ModerationException;
use App\Domain\Reports\ReportException;
use App\Domain\Rooms\RoomException;
use App\Domain\Store\LevelException;
use App\Domain\Support\SupportException;
use App\Domain\Users\SanctionException;
use App\Domain\Wallet\WalletException;
use App\Http\Middleware\AttachRequestId;
use App\Http\Middleware\CaptureUnauditedMutations;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\EnsureAdminActive;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRole;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            AttachRequestId::class,
        ]);

        // A.10d safety net. Appended rather than prepended so it runs closest to the
        // route and sees the status the controller actually returned; it stays silent
        // whenever a service already wrote a proper audit row.
        $middleware->api(append: [
            CaptureUnauditedMutations::class,
        ]);

        $middleware->alias([
            'permission'   => EnsurePermission::class,
            'role'         => EnsureRole::class,
            'admin.active' => EnsureAdminActive::class,
            'admin.idle'   => EnforceIdleTimeout::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // docs/03 §2.1 — "Every response, success or failure, has the same shape. No
        // exceptions." That has to be enforced here, or the framework's own error pages
        // leak a different shape the panel cannot parse.
        $exceptions->render(function (ScopeException $e, Request $request) {
            return $request->expectsJson() || $request->is('api/*') ? $e->render() : null;
        });

        $exceptions->render(function (PermissionException $e, Request $request) {
            return $request->expectsJson() || $request->is('api/*') ? $e->render() : null;
        });

        $exceptions->render(function (WalletException $e, Request $request) {
            return $request->expectsJson() || $request->is('api/*') ? $e->render() : null;
        });

        $exceptions->render(function (SanctionException $e, Request $request) {
            return $request->expectsJson() || $request->is('api/*') ? $e->render() : null;
        });

        $exceptions->render(function (RoomException $e, Request $request) {
            return $request->expectsJson() || $request->is('api/*') ? $e->render() : null;
        });

        $exceptions->render(function (EconomyException $e, Request $request) {
            return $request->expectsJson() || $request->is('api/*') ? $e->render() : null;
        });

        $exceptions->render(function (EventException $e, Request $request) {
            return $request->expectsJson() || $request->is('api/*') ? $e->render() : null;
        });

        $exceptions->render(function (ModerationException $e, Request $request) {
            return $request->expectsJson() || $request->is('api/*') ? $e->render() : null;
        });

        $exceptions->render(function (AgencyException $e, Request $request) {
            return $request->expectsJson() || $request->is('api/*') ? $e->render() : null;
        });

        $exceptions->render(function (CmsException $e, Request $request) {
            return $request->expectsJson() || $request->is('api/*') ? $e->render() : null;
        });

        $exceptions->render(function (SupportException $e, Request $request) {
            return $request->expectsJson() || $request->is('api/*') ? $e->render() : null;
        });

        $exceptions->render(function (ReportException $e, Request $request) {
            return $request->expectsJson() || $request->is('api/*') ? $e->render() : null;
        });

        $exceptions->render(function (LevelException $e, Request $request) {
            return $request->expectsJson() || $request->is('api/*') ? $e->render() : null;
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! ($request->expectsJson() || $request->is('api/*'))) {
                return null;
            }

            return ApiResponse::error('VALIDATION_ERROR', 'Validation failed', $e->errors(), 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! ($request->expectsJson() || $request->is('api/*'))) {
                return null;
            }

            return ApiResponse::error('UNAUTHENTICATED', 'Missing or invalid token', null, 401);
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! ($request->expectsJson() || $request->is('api/*'))) {
                return null;
            }

            $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

            return ApiResponse::error(
                'RATE_LIMITED',
                'Too many requests',
                $retryAfter === null ? null : ['retry_after' => (int) $retryAfter],
                429,
            )->withHeaders($e->getHeaders());
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (! ($request->expectsJson() || $request->is('api/*'))) {
                return null;
            }

            return ApiResponse::error('NOT_FOUND', 'Resource not found', null, 404);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! ($request->expectsJson() || $request->is('api/*'))) {
                return null;
            }

            return ApiResponse::error('NOT_FOUND', 'Resource not found', null, 404);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->expectsJson() || $request->is('api/*'))) {
                return null;
            }

            // Let the framework render in local/testing so stack traces stay available.
            if (config('app.debug')) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface) {
                return ApiResponse::error('FORBIDDEN', $e->getMessage() ?: 'Forbidden', null, $e->getStatusCode());
            }

            return ApiResponse::error(
                'SERVER_ERROR',
                'Something went wrong',
                ['request_id' => $request->attributes->get('request_id')],
                500,
            );
        });
    })->create();
