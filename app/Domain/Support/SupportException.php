<?php

namespace App\Domain\Support;

use App\Support\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;

class SupportException extends Exception
{
    public readonly string $errorCode;
    public readonly int $status;

    public function __construct(string $errorCode, string $message, int $status = 422)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->status = $status;
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->errorCode, $this->getMessage(), null, $this->status);
    }
}
