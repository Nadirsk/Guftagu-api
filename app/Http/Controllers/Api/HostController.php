<?php

namespace App\Http\Controllers\Api;

use App\Domain\Agency\AgencyException;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Host;
use App\Models\HostApplication;
use App\Models\HostEarning;
use App\Models\HostTarget;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** D.9a — applying to become a host and join an agency. Review stays Admin/Manager-side. */
class HostController extends Controller
{
    /** GET /agencies — open to hosts, approved only. */
    public function agencies(): JsonResponse
    {
        $agencies = Agency::query()
            ->where('status', Agency::APPROVED)
            ->orderBy('name')
            ->get();

        return ApiResponse::success($agencies->map(fn (Agency $a) => [
            'uuid'        => $a->uuid,
            'code'        => $a->code,
            'name'        => $a->name,
            'logo_url'    => $a->logo_url,
            'description' => $a->description,
        ])->all());
    }

    /** POST /host/apply — `{agency_id, intro_audio_url?, experience?}`. */
    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id'       => ['required', 'integer', 'exists:agencies,id'],
            'intro_audio_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'experience'      => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();

        if (Host::where('user_id', $user->id)->where('status', Host::APPROVED)->exists()) {
            throw new AgencyException('BAD_REQUEST', 'You are already a host.', 400);
        }

        if (HostApplication::where('user_id', $user->id)->where('status', HostApplication::PENDING)->exists()) {
            throw new AgencyException('BAD_REQUEST', 'You already have an application pending review.', 400);
        }

        $application = HostApplication::create([
            'user_id'         => $user->id,
            'agency_id'       => $data['agency_id'],
            'intro_audio_url' => $data['intro_audio_url'] ?? null,
            'experience'      => $data['experience'] ?? null,
            'status'          => HostApplication::PENDING,
        ]);

        return ApiResponse::success(['id' => $application->id, 'status' => $application->status], 'Application submitted', 201);
    }

    /** GET /host/status */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        $host = Host::where('user_id', $user->id)->latest('id')->first();
        $application = HostApplication::where('user_id', $user->id)->latest('id')->first();

        return ApiResponse::success([
            'is_host'          => $host?->status === Host::APPROVED,
            'host_status'      => $host?->status,
            'latest_application' => $application === null ? null : [
                'id'          => $application->id,
                'status'      => $application->status,
                'reason'      => $application->reason,
                'submitted_at' => $application->created_at?->toIso8601ZuluString(),
            ],
        ]);
    }

    /** GET /host/earnings — D.9b, daily rollup for the caller's own host record. */
    public function earnings(Request $request): JsonResponse
    {
        $host = $this->approvedHostOrFail($request);

        $rows = $host->earnings()->limit(90)->get();

        return ApiResponse::success($rows->map(fn (HostEarning $e) => [
            'date'            => $e->date->toDateString(),
            'diamonds_earned' => $e->diamonds_earned,
            'net_paise'       => $e->net_paise,
            'room_hours'      => $e->room_hours,
            'gift_count'      => $e->gift_count,
        ])->all());
    }

    /** GET /host/targets — D.9b, the current open period. */
    public function targets(Request $request): JsonResponse
    {
        $host = $this->approvedHostOrFail($request);

        $target = $host->targets()->where('status', HostTarget::ACTIVE)->first();

        return ApiResponse::success($target === null ? null : [
            'period_start'      => $target->period_start->toDateString(),
            'period_end'        => $target->period_end->toDateString(),
            'target_diamonds'   => $target->target_diamonds,
            'achieved_diamonds' => $target->achieved_diamonds,
            'achievement_pct'   => $target->achievement_pct,
            'is_open'           => $target->isOpen(),
        ]);
    }

    protected function approvedHostOrFail(Request $request): Host
    {
        $host = Host::where('user_id', $request->user()->id)->where('status', Host::APPROVED)->first();

        if ($host === null) {
            throw new AgencyException('FORBIDDEN', 'You are not an approved host.', 403);
        }

        return $host;
    }
}
