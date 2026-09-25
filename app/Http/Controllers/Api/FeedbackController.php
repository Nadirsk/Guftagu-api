<?php

namespace App\Http\Controllers\Api;

use App\Domain\Media\ImageUploadService;
use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The Me screen's Feedback form — a problem description, a required contact, and up to 6 photos. */
class FeedbackController extends Controller
{
    public function __construct(protected ImageUploadService $uploads)
    {
    }

    /** POST /feedback (multipart/form-data) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Matches the screen's own DESCRIPTION_MAX/CONTACT_MAX caps — both
            // fields are marked required on the screen (red *) so support
            // always has a way to follow up.
            'description' => ['required', 'string', 'max:120'],
            'contact' => ['required', 'string', 'max:30'],
            // "Upload multiple images at a time" — capped at 6 so one feedback
            // report can't be used to bulk-upload arbitrary files.
            'images' => ['sometimes', 'array', 'max:6'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $feedback = $request->user()->feedback()->create([
            'description' => $data['description'],
            'contact' => $data['contact'],
        ]);

        foreach ($request->file('images', []) as $image) {
            $result = $this->uploads->store($image, 'feedback/'.$feedback->id);
            $feedback->images()->create(['path' => $result['path']]);
        }

        return ApiResponse::success(
            $this->present($feedback->load('images')),
            'Thanks for your feedback!',
            201,
        );
    }

    protected function present(Feedback $feedback): array
    {
        return [
            'uuid' => $feedback->uuid,
            'description' => $feedback->description,
            'contact' => $feedback->contact,
            'images' => $feedback->images->map(fn ($image) => $image->url())->all(),
            'created_at' => $feedback->created_at?->toIso8601String(),
        ];
    }
}
