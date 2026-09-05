<?php

namespace App\Domain\Chat;

use App\Support\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;

class ChatException extends Exception
{
    public readonly string $errorCode;
    public readonly int $status;

    public function __construct(string $errorCode, string $message, int $status = 422)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->status = $status;
    }

    public static function notAParticipant(): self
    {
        // 404 rather than 403: a conversation you are not in should not be confirmable.
        return new self('NOT_FOUND', 'Resource not found', 404);
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->errorCode, $this->getMessage(), null, $this->status);
    }
}
