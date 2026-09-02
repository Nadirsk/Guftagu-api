<?php

namespace App\Domain\Audit;

use App\Models\AdminUser;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * docs/02 §13 / A.10d — append-only admin audit trail, 1-year retention (docs/01 §6, OWASP A09).
 *
 * Every state-changing admin action writes one row. Failed authorisation attempts are
 * logged too: the A.11 acceptance criteria require a refused escalation to be recorded.
 *
 * **Explicit logging is the primary mechanism, not a middleware.** Only the service
 * performing a change knows what the before and after actually were; a middleware can see
 * a request body and a status code and nothing else. `CaptureUnauditedMutations` exists as
 * a safety net behind this, and it stays quiet whenever `log()` has already run — A.10d
 * asks for *one* row per mutation, so a belt-and-braces double write would fail it.
 */
class AuditLogger
{
    /** Request attribute the safety-net middleware reads. */
    public const MARKER = 'audit.logged';

    public function __construct(protected Request $request)
    {
    }

    public function log(
        ?AdminUser $actor,
        string $action,
        string $module,
        ?string $entityType = null,
        int|string|null $entityId = null,
        ?array $before = null,
        ?array $after = null,
    ): AuditLog {
        // Marks this request as accounted for, so the safety net does not add a second,
        // vaguer row describing the same action.
        $this->request->attributes->set(self::MARKER, true);

        return AuditLog::create([
            'admin_user_id' => $actor?->id,
            'action'        => $action,
            'module'        => $module,
            'entity_type'   => $entityType,
            'entity_id'     => $entityId === null ? null : (string) $entityId,
            'before'        => $before,
            'after'         => $after,
            'ip'            => $this->request->ip(),
            'user_agent'    => str($this->request->userAgent() ?? '')->limit(490)->value(),
            'request_id'    => $this->request->attributes->get('request_id'),
        ]);
    }

    /** Whether anything has been logged for the current request. */
    public function hasLogged(): bool
    {
        return (bool) $this->request->attributes->get(self::MARKER, false);
    }
}
