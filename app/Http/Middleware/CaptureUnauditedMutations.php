<?php

namespace App\Http\Middleware;

use App\Domain\Audit\AuditLogger;
use App\Models\AdminUser;
use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GFT-108 — the audit safety net (A.10d).
 *
 * A.10d requires that **any** admin mutation leaves an audit row. Services log explicitly,
 * because only they know the real before and after. This catches the gap: a mutating
 * request that succeeded and that no service logged.
 *
 * It runs *after* the response so it can see the status code, and it writes at most one
 * row — `AuditLogger` marks the request when it has already logged, so the criterion's
 * "one row" is not turned into two.
 *
 * The row it writes is deliberately thinner than a service-written one: `before` is
 * unknowable from here, and `after` is the request payload with secrets stripped. It says
 * `"source": "middleware"` so nobody mistakes a fallback for a considered audit entry, and
 * so the gaps are greppable and can be given proper logging later.
 */
class CaptureUnauditedMutations
{
    protected const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Never store these, whatever the endpoint calls them. */
    protected const REDACT = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'otp', 'code', 'secret', 'mfa_code', 'api_key',
    ];

    /**
     * Endpoints that mutate nothing worth auditing. A read that happens to be a POST
     * (a preview, a filter test) would otherwise fill the log with noise, and a log nobody
     * can scan is a log nobody reads.
     */
    protected const IGNORE_SUFFIXES = [
        'filter-test', 'preview', 'reports/preview', 'logout', 'refresh',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldCapture($request, $response)) {
            return $response;
        }

        AuditLog::create([
            'admin_user_id' => $request->user()?->id,
            'action'        => $this->action($request),
            'module'        => $this->module($request),
            'entity_type'   => null,
            'entity_id'     => null,
            'before'        => null,
            'after'         => ['source' => 'middleware'] + $this->payload($request),
            'ip'            => $request->ip(),
            'user_agent'    => str($request->userAgent() ?? '')->limit(490)->value(),
            'request_id'    => $request->attributes->get('request_id'),
        ]);

        return $response;
    }

    protected function shouldCapture(Request $request, Response $response): bool
    {
        if (! in_array($request->method(), self::MUTATING, true)) {
            return false;
        }

        // A rejected request changed nothing. The refusals that *are* worth recording
        // (a denied permission escalation) are logged explicitly by the service.
        if ($response->getStatusCode() >= 300) {
            return false;
        }

        // Read the marker straight off the request rather than resolving the logger:
        // the attribute is where the fact lives, and this cannot go wrong if the container
        // ever hands out a different Request instance.
        if ($request->attributes->get(AuditLogger::MARKER, false)) {
            return false;
        }

        // `audit_logs.admin_user_id` is a foreign key onto `admin_users`. Now that the
        // mobile group exists (D.3, D.4) this middleware also sees requests authenticated
        // as a `User`, and writing that id here would either break the constraint or —
        // worse — silently attribute an app action to whichever admin shares the id. A.10d
        // is about admin mutations; app traffic is recorded by its own domain rows.
        if (! $request->user() instanceof AdminUser) {
            return false;
        }

        foreach (self::IGNORE_SUFFIXES as $suffix) {
            if (str_ends_with($request->path(), $suffix)) {
                return false;
            }
        }

        return true;
    }

    /** `settlements.approve` from `api/v1/admin/settlements/12/approve`. */
    protected function action(Request $request): string
    {
        $name = $request->route()?->getName();

        if (is_string($name) && $name !== '') {
            return str($name)->after('admin.')->limit(95, '')->value();
        }

        return str(strtolower($request->method()).' '.$request->path())->limit(95, '')->value();
    }

    protected function module(Request $request): string
    {
        $segments = explode('/', $request->path());
        $index = array_search('admin', $segments, true);

        return $index !== false && isset($segments[$index + 1])
            ? substr($segments[$index + 1], 0, 50)
            : 'admin';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        // `input()`, not `except()`: the latter reads `all()`, which merges uploaded files
        // in, and an UploadedFile is not JSON-encodable — the audit write then throws and
        // takes the whole request down with it. An audit trail must never be able to break
        // the thing it is auditing.
        $input = collect($request->input())->except(self::REDACT)->all();

        // A bulk request can carry hundreds of ids, and a CMS page carries an entire
        // document. Recording the shape is useful; recording the whole payload turns the
        // audit table into a second copy of the database.
        array_walk_recursive($input, function (&$value) {
            if (is_string($value) && strlen($value) > 500) {
                $value = substr($value, 0, 500).'…';
            }
        });

        $payload = ['payload' => $input, 'path' => $request->path(), 'method' => $request->method()];

        // Last line of defence: anything left that will not encode (invalid UTF-8 from a
        // binary field, a resource) is replaced rather than allowed to throw.
        if (json_encode($payload) === false) {
            $payload = [
                'payload' => ['note' => 'Payload was not JSON-encodable and has been omitted.'],
                'path'    => $request->path(),
                'method'  => $request->method(),
            ];
        }

        return $payload;
    }
}
