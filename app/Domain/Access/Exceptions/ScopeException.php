<?php

namespace App\Domain\Access\Exceptions;

use App\Support\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * B.1a — a direct API call for an out-of-scope id returns 403.
 *
 * Deliberately separate from `PermissionException`. "You lack this permission" and "you
 * hold this permission but not for that agency" are different facts, and an operator
 * debugging an access problem needs to know which one they are looking at. Collapsing both
 * into `PERMISSION_DENIED` sends somebody hunting for a missing grant that is already
 * there.
 *
 * 403 rather than 404: the caller is authenticated staff, and pretending the record does
 * not exist would have them chasing a phantom data problem.
 */
class ScopeException extends Exception
{
    public function __construct(
        public readonly string $errorCode = 'OUT_OF_SCOPE',
        string $message = '',
        public readonly ?array $details = null,
        public readonly int $status = 403,
    ) {
        parent::__construct($message !== '' ? $message : 'That record is outside your assigned scope.');
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->errorCode, $this->getMessage(), $this->details, $this->status);
    }
}
