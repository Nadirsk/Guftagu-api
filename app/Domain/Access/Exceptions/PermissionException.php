<?php

namespace App\Domain\Access\Exceptions;

use App\Support\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Carries one of the docs/03 §15 error codes out of the domain layer and renders
 * itself in the standard envelope, so a guard failure cannot accidentally leak a
 * stack trace or a non-conforming body.
 */
class PermissionException extends Exception
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
        public readonly ?array $details = null,
        public readonly int $status = 403,
    ) {
        parent::__construct($message !== '' ? $message : static::defaultMessage($errorCode));
    }

    public static function escalation(array $ungranted): self
    {
        return new self(
            'PERMISSION_ESCALATION_DENIED',
            'You cannot grant permissions you do not hold',
            ['ungranted' => array_values($ungranted)],
        );
    }

    public static function selfGrant(): self
    {
        return new self('SELF_GRANT_DENIED', 'You cannot grant permissions to yourself');
    }

    public static function delegationTarget(): self
    {
        return new self('DELEGATION_TARGET_DENIED', 'You are not allowed to grant permissions to that role');
    }

    public static function mfaRequired(array $details = []): self
    {
        return new self('MFA_REQUIRED', 'Re-authentication is required for this action', $details ?: null);
    }

    public static function denied(string $key): self
    {
        return new self('PERMISSION_DENIED', 'You do not have permission to perform this action', ['permission' => $key]);
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->errorCode, $this->getMessage(), $this->details, $this->status);
    }

    protected static function defaultMessage(string $code): string
    {
        return match ($code) {
            'PERMISSION_DENIED'            => 'You do not have permission to perform this action',
            'PERMISSION_ESCALATION_DENIED' => 'You cannot grant permissions you do not hold',
            'DELEGATION_TARGET_DENIED'     => 'You are not allowed to grant permissions to that role',
            'SELF_GRANT_DENIED'            => 'You cannot grant permissions to yourself',
            'MFA_REQUIRED'                 => 'Re-authentication is required for this action',
            default                        => 'Forbidden',
        };
    }
}
