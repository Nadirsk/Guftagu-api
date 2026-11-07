<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A person reporting another person from the app (Report screen, Figma 514:3014).
 *
 * This writes to the same `reports` table the Moderator queue reads, so a report is in
 * the queue the moment it is submitted: there is no sync step and nothing to be late.
 * Everything the queue sorts on (priority, status) is set here, not by the reporter, so
 * nobody can put their own report at the top.
 */
class ReportController extends Controller
{
    /**
     * Priority follows how bad the category is, not how the reporter feels about it.
     * The Moderator can still re-rank it; this only decides where it lands.
     */
    private const PRIORITY = [
        'underage'   => 'critical',
        'nudity'     => 'high',
        'abuse'      => 'high',
        'fraud'      => 'high',
        'harassment' => 'medium',
        'spam'       => 'low',
        'other'      => 'low',
    ];

    /** POST /reports */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_uuid'     => ['required', 'uuid'],
            'category'        => ['required', Rule::in(Report::CATEGORIES)],
            'description'     => ['required', 'string', 'min:3', 'max:1000'],
            'evidence_urls'   => ['sometimes', 'array', 'max:5'],
            'evidence_urls.*' => ['url', 'max:500'],
        ]);

        $reporter = $request->user();
        $target = User::query()->where('uuid', $data['target_uuid'])->first();

        if ($target === null) {
            return ApiResponse::error('NOT_FOUND', 'That user could not be found.', null, 404);
        }

        if ($target->id === $reporter->id) {
            return ApiResponse::error('CANNOT_REPORT_SELF', 'You cannot report yourself.', null, 422);
        }

        // One live report per person per target. Sending the same complaint twice does not
        // make it more urgent; it makes the queue longer.
        $alreadyOpen = Report::query()
            ->where('reporter_id', $reporter->id)
            ->where('target_type', 'user')
            ->where('target_id', $target->id)
            ->whereIn('status', [Report::OPEN, Report::ASSIGNED, Report::ESCALATED])
            ->exists();

        if ($alreadyOpen) {
            return ApiResponse::error(
                'ALREADY_REPORTED',
                'You have already reported this user. Our team is reviewing it.',
                null,
                409,
            );
        }

        $report = Report::create([
            'reporter_id'   => $reporter->id,
            'target_type'   => 'user',
            'target_id'     => $target->id,
            'category'      => $data['category'],
            'description'   => trim($data['description']),
            'evidence_urls' => $data['evidence_urls'] ?? null,
            'priority'      => self::PRIORITY[$data['category']] ?? 'medium',
            'status'        => Report::OPEN,
        ]);

        return ApiResponse::success([
            'uuid'       => $report->uuid,
            'status'     => $report->status,
            'created_at' => $report->created_at?->toIso8601ZuluString(),
        ], 'Report sent', 201);
    }
}
