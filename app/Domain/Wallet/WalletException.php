<?php

namespace App\Domain\Wallet;

use App\Support\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Carries a docs/03 §15 error code out of the wallet domain and renders itself in the
 * standard envelope, so a money failure can never leak a stack trace or an off-contract body.
 */
class WalletException extends Exception
{
    public readonly string $errorCode;
    public readonly int $status;
    /** @var array<string, mixed>|null */
    public readonly ?array $details;

    public function __construct(string $errorCode, string $message, int $status = 422, ?array $details = null)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->status = $status;
        $this->details = $details;
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->errorCode, $this->getMessage(), $this->details, $this->status);
    }
}
