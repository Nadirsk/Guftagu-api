<?php

namespace App\Domain\Agency;

use App\Domain\Audit\AuditLogger;
use App\Models\AdminUser;
use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\Host;
use App\Models\HostApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * GFT-080 / GFT-081 — agency and host lifecycle (A.8a).
 *
 * The gate that matters: **only an approved agency may take hosts.** A pending agency
 * accumulating hosts is a queue of people who think they have been onboarded and have not.
 */
class AgencyService
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    /**
     * @throws AgencyException
     */
    public function approve(Agency $agency, AdminUser $actor): Agency
    {
        if ($agency->status === Agency::APPROVED) {
            throw new AgencyException('BAD_REQUEST', 'That agency is already approved.', 400);
        }

        // A.8a says documents are reviewed before approval. Approving one with nothing
        // uploaded is almost always a misclick, so it is refused rather than logged.
        if (empty($agency->documents)) {
            throw new AgencyException(
                'DOCUMENTS_MISSING',
                'This agency has no documents on file. Approving it would record a review that never happened.',
                422,
            );
        }

        $before = ['status' => $agency->status];

        $agency->forceFill([
            'status'           => Agency::APPROVED,
            'approved_by'      => $actor->id,
            'approved_at'      => now(),
            'rejection_reason' => null,
        ])->save();

        $this->audit->log($actor, 'agency.approve', 'agency', Agency::class, $agency->id, $before, [
            'status' => Agency::APPROVED,
        ]);

        return $agency->refresh();
    }

    /**
     * @throws AgencyException
     */
    public function reject(Agency $agency, string $reason, AdminUser $actor): Agency
    {
        if (trim($reason) === '') {
            throw new AgencyException('VALIDATION_ERROR', 'A reason is required — the applicant is told it.', 422);
        }

        $before = ['status' => $agency->status];

        $agency->forceFill([
            'status'           => Agency::REJECTED,
            'rejection_reason' => trim($reason),
            'approved_by'      => null,
            'approved_at'      => null,
        ])->save();

        $this->audit->log($actor, 'agency.reject', 'agency', Agency::class, $agency->id, $before, [
            'status' => Agency::REJECTED, 'reason' => trim($reason),
        ]);

        return $agency->refresh();
    }

    /**
     * Suspending an agency does **not** cascade to its hosts.
     *
     * Their contracts and earnings are their own; cutting off a host because their agency
     * is under review would punish the wrong person, and unwinding it later is worse. The
     * agency simply stops being settled and stops accepting new hosts.
     *
     * @throws AgencyException
     */
    public function suspend(Agency $agency, string $reason, AdminUser $actor): Agency
    {
        if ($agency->status === Agency::SUSPENDED) {
            throw new AgencyException('BAD_REQUEST', 'That agency is already suspended.', 400);
        }

        $before = ['status' => $agency->status];

        $agency->forceFill(['status' => Agency::SUSPENDED, 'rejection_reason' => trim($reason)])->save();

        $this->audit->log($actor, 'agency.suspend', 'agency', Agency::class, $agency->id, $before, [
            'status' => Agency::SUSPENDED, 'reason' => trim($reason),
        ]);

        return $agency->refresh();
    }

    /**
     * @throws AgencyException
     */
    public function reinstate(Agency $agency, AdminUser $actor): Agency
    {
        if ($agency->status !== Agency::SUSPENDED) {
            throw new AgencyException('BAD_REQUEST', 'Only a suspended agency can be reinstated.', 400);
        }

        $before = ['status' => $agency->status];

        $agency->forceFill(['status' => Agency::APPROVED, 'rejection_reason' => null])->save();

        $this->audit->log($actor, 'agency.reinstate', 'agency', Agency::class, $agency->id, $before, [
            'status' => Agency::APPROVED,
        ]);

        return $agency->refresh();
    }

    // ------------------------------------------------------------------- hosts

    /**
     * Approve a host application, creating (or reactivating) the host record.
     *
     * @throws AgencyException
     */
    public function approveApplication(HostApplication $application, ?Agency $agency, AdminUser $actor): Host
    {
        if (! $application->isPending()) {
            throw new AgencyException('BAD_REQUEST', "That application is already {$application->status}.", 400);
        }

        $agency ??= $application->agency;

        if ($agency !== null && ! $agency->isApproved()) {
            throw new AgencyException(
                'AGENCY_NOT_APPROVED',
                "{$agency->name} is {$agency->status}, so hosts cannot be assigned to it yet.",
                422,
            );
        }

        return DB::transaction(function () use ($application, $agency, $actor) {
            // A person who left and came back is the same host row, reactivated — a second
            // row would split their earnings history in two.
            $host = Host::firstOrNew(['user_id' => $application->user_id]);

            $host->fill([
                'agency_id'   => $agency?->id,
                'status'      => Host::APPROVED,
                'applied_at'  => $host->applied_at ?? $application->created_at,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ])->save();

            if ($agency !== null) {
                $this->joinAgency($host, $agency);
            }

            $application->forceFill([
                'status'      => HostApplication::APPROVED,
                'agency_id'   => $agency?->id,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ])->save();

            $this->audit->log($actor, 'host.approve', 'agency', Host::class, $host->id, null, [
                'application_id' => $application->id, 'agency_id' => $agency?->id,
            ]);

            return $host;
        });
    }

    /**
     * @throws AgencyException
     */
    public function rejectApplication(HostApplication $application, string $reason, AdminUser $actor): HostApplication
    {
        if (! $application->isPending()) {
            throw new AgencyException('BAD_REQUEST', "That application is already {$application->status}.", 400);
        }

        if (trim($reason) === '') {
            throw new AgencyException('VALIDATION_ERROR', 'A reason is required — the applicant is told it.', 422);
        }

        $application->forceFill([
            'status'      => HostApplication::REJECTED,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'reason'      => trim($reason),
        ])->save();

        $this->audit->log($actor, 'host.reject', 'agency', HostApplication::class, $application->id, null, [
            'reason' => trim($reason),
        ]);

        return $application->refresh();
    }

    /**
     * Move a host between agencies.
     *
     * The membership history is closed and reopened rather than edited: which agency a
     * host belonged to *during a period* is what a settlement is computed from, so
     * overwriting it would silently re-price the past.
     *
     * @throws AgencyException
     */
    public function assignToAgency(Host $host, ?Agency $agency, AdminUser $actor): Host
    {
        if ($agency !== null && ! $agency->isApproved()) {
            throw new AgencyException(
                'AGENCY_NOT_APPROVED',
                "{$agency->name} is {$agency->status}, so hosts cannot be assigned to it.",
                422,
            );
        }

        $before = ['agency_id' => $host->agency_id];

        DB::transaction(function () use ($host, $agency) {
            AgencyMember::query()
                ->where('user_id', $host->user_id)
                ->where('is_active', true)
                ->update(['is_active' => false, 'left_at' => now()]);

            $host->forceFill(['agency_id' => $agency?->id])->save();

            if ($agency !== null) {
                $this->joinAgency($host, $agency);
            }
        });

        $this->audit->log($actor, 'host.assign_agency', 'agency', Host::class, $host->id, $before, [
            'agency_id' => $agency?->id,
        ]);

        return $host->refresh();
    }

    /**
     * @throws AgencyException
     */
    public function setHostStatus(Host $host, string $status, ?string $note, AdminUser $actor): Host
    {
        if (! in_array($status, Host::STATUSES, true)) {
            throw new AgencyException('VALIDATION_ERROR', 'That is not a host status.', 422);
        }

        $before = ['status' => $host->status];

        $host->forceFill(['status' => $status, 'notes' => $note ?? $host->notes])->save();

        if (in_array($status, [Host::LEFT, Host::REJECTED], true)) {
            AgencyMember::query()
                ->where('user_id', $host->user_id)
                ->where('is_active', true)
                ->update(['is_active' => false, 'left_at' => now()]);
        }

        $this->audit->log($actor, 'host.status', 'agency', Host::class, $host->id, $before, [
            'status' => $status, 'note' => $note,
        ]);

        return $host->refresh();
    }

    protected function joinAgency(Host $host, Agency $agency): void
    {
        AgencyMember::create([
            'agency_id' => $agency->id,
            'user_id'   => $host->user_id,
            'role'      => 'host',
            'joined_at' => now(),
            'is_active' => true,
        ]);
    }

    /** Who a user belonged to on a given day — what settlement arithmetic needs. */
    public function agencyIdOn(User $user, \Illuminate\Support\Carbon $day): ?int
    {
        $member = AgencyMember::query()
            ->where('user_id', $user->id)
            ->where('joined_at', '<=', $day->copy()->endOfDay())
            ->where(fn ($q) => $q->whereNull('left_at')->orWhere('left_at', '>=', $day->copy()->startOfDay()))
            ->orderByDesc('joined_at')
            ->first();

        return $member?->agency_id;
    }
}
