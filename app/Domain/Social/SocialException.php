<?php

namespace App\Domain\Social;

use App\Support\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;

class SocialException extends Exception
{
    public readonly string $errorCode;
    public readonly int $status;

    public function __construct(string $errorCode, string $message, int $status = 422)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->status = $status;
    }

    public static function blocked(): self
    {
        // 403 and a message that does not say which side blocked whom. Telling the blocked
        // party "they blocked you" hands them information the block was meant to withhold.
        return new self('BLOCKED', 'This action is not available between these accounts.', 403);
    }

    /**
     * Direct messaging requires a mutual follow. Unlike a block this *is* actionable by the
     * caller — the fix is to follow them and wait for a follow back — so the message says
     * what to do rather than hiding behind a generic refusal.
     */
    public static function notFriends(): self
    {
        return new self('NOT_FRIENDS', 'You can only message people who follow you back.', 403);
    }

    public static function notVisible(): self
    {
        // 404, not 403. D.3d says a non-follower "cannot see it ... by direct id" — a 403
        // confirms the post exists, which is half of what was being hidden.
        return new self('NOT_FOUND', 'Resource not found', 404);
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->errorCode, $this->getMessage(), null, $this->status);
    }
}
