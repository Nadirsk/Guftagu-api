<?php

namespace App\Http\Controllers\Api;

use App\Domain\Agency\AgencyException;
use App\Domain\Agency\AgencyService;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\HostApplication;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** D.9a — a user creating and reviewing their own agency. Admin keeps a separate, approving/overriding path in Admin\AgencyController and Admin\HostController. */
class AgencyController extends Controller
{
    public function __construct(protected AgencyService $agencies)
    {
    }

    /**
     * POST /agency/apply — a user creates their own agency, `{name, logo_url?, description?,
     * contact_phone?, contact_email?, documents?}`. It starts `pending` and stays unusable
     * (cannot take hosts) until an admin approves it — and approval itself refuses an agency
     * with no documents on file (`AgencyService::approve`), so submitting documents here is
     * how an applicant avoids a bounce back later.
     */
    public function apply(Request $request): JsonResponse
    {
        $user = $request->user();

        if (Agency::where('owner_user_id', $user->id)->where('status', Agency::APPROVED)->exists()) {
            throw new AgencyException('BAD_REQUEST', 'You already have an approved agency.', 400);
        }

        if (Agency::where('owner_user_id', $user->id)->where('status', Agency::PENDING)->exists()) {
            throw new AgencyException('BAD_REQUEST', 'You already have an agency application pending review.', 400);
        }

        $data = $request->validate([
            'name'              => ['required', 'string', 'min:2', 'max:120'],
            'logo_url'          => ['sometimes', 'nullable', 'url', 'max:500'],
            'description'       => ['sometimes', 'nullable', 'string', 'max:2000'],
            'contact_phone'     => ['sometimes', 'nullable', 'string', 'max:20'],
            'contact_email'     => ['sometimes', 'nullable', 'email', 'max:120'],
            'documents'         => ['sometimes', 'array'],
            'documents.*.type'  => ['required_with:documents', 'string', 'max:40'],
            'documents.*.url'   => ['required_with:documents', 'url', 'max:500'],
        ]);

        $documents = collect($data['documents'] ?? [])
            ->map(fn (array $d) => ['type' => $d['type'], 'url' => $d['url'], 'uploaded_at' => now()->toIso8601ZuluString()])
            ->all();

        $agency = Agency::create([
            'code'          => Agency::nextCode(),
            'name'          => $data['name'],
            'owner_user_id' => $user->id,
            'logo_url'      => $data['logo_url'] ?? null,
            'description'   => $data['description'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'documents'     => $documents ?: null,
            'status'        => Agency::PENDING,
        ]);

        return ApiResponse::success([
            'id'     => $agency->id,
            'uuid'   => $agency->uuid,
            'code'   => $agency->code,
            'status' => $agency->status,
        ], 'Agency application submitted', 201);
    }

    /** GET /agency/status — the caller's own agency application, whatever state it is in. */
    public function status(Request $request): JsonResponse
    {
        $agency = Agency::where('owner_user_id', $request->user()->id)->latest('id')->first();

        return ApiResponse::success($agency === null ? null : [
            'id'               => $agency->id,
            'uuid'             => $agency->uuid,
            'code'             => $agency->code,
            'name'             => $agency->name,
            'status'           => $agency->status,
            'is_approved'      => $agency->isApproved(),
            'rejection_reason' => $agency->rejection_reason,
            'document_count'   => count($agency->documents ?? []),
            'created_at'       => $agency->created_at?->toIso8601ZuluString(),
        ]);
    }

    /** GET /agency/host-applications — pending applications for the agencies this user owns. */
    public function applications(Request $request): JsonResponse
    {
        $agencyIds = Agency::where('owner_user_id', $request->user()->id)->pluck('id');

        $applications = HostApplication::query()
            ->whereIn('agency_id', $agencyIds)
            ->pending()
            ->with('user:id,guftagu_id')
            ->with('user.profile:id,user_id,display_name,avatar_url')
            ->latest('id')
            ->get();

        return ApiResponse::success($applications->map(fn (HostApplication $a) => [
            'id'              => $a->id,
            'guftagu_id'      => $a->user?->guftagu_id,
            'display_name'    => $a->user?->profile?->display_name,
            'avatar_url'      => $a->user?->profile?->avatar_url,
            'intro_audio_url' => $a->intro_audio_url,
            'experience'      => $a->experience,
            'submitted_at'    => $a->created_at?->toIso8601ZuluString(),
        ])->all());
    }

    /** POST /agency/host-applications/{application}/approve */
    public function approveApplication(Request $request, HostApplication $application): JsonResponse
    {
        $agency = $this->ownedAgencyOrFail($request, $application);

        $host = $this->agencies->approveApplication($application, $agency, $request->user());

        return ApiResponse::success(['host_id' => $host->id], 'Host approved');
    }

    /** POST /agency/host-applications/{application}/reject — `{reason}`. */
    public function rejectApplication(Request $request, HostApplication $application): JsonResponse
    {
        $this->ownedAgencyOrFail($request, $application);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $this->agencies->rejectApplication($application, $data['reason'], $request->user());

        return ApiResponse::success(null, 'Application rejected');
    }

    /** An owner may only act on applications addressed to their own agency. */
    protected function ownedAgencyOrFail(Request $request, HostApplication $application): Agency
    {
        if ($application->agency_id === null) {
            throw new AgencyException('BAD_REQUEST', 'This application was not addressed to any agency.', 400);
        }

        $agency = Agency::find($application->agency_id);

        if ($agency === null || $agency->owner_user_id !== $request->user()->id) {
            throw new AgencyException('FORBIDDEN', 'You do not own the agency this application was sent to.', 403);
        }

        return $agency;
    }
}
