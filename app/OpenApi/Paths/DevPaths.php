<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI operations for DevHelperController — registered only when APP_ENV=local.
 */
#[OA\Get(
    path: '/admin/dev/last-otp',
    summary: 'Read the latest MFA code (local only)',
    description: <<<'MD'
**This route only exists when `APP_ENV=local`.** It is registered inside an environment
check in `routes/api.php`, not behind a permission, so there is no gate to misconfigure.

MFA is on for Super Admin and Admin, so testing anything here means fetching a 6-digit
code. OTPs are stored bcrypt-hashed and cannot be read back, so this parses the code out
of the mail log (`MAIL_MAILER=log`) and reports the pending challenge alongside it.

Use it like this: `POST /admin/auth/login` → call this → paste `challenge_id` and `otp`
into `POST /admin/auth/mfa/verify` → **Authorize** with the token you get back.
MD,
    tags: ['Dev Helpers'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'The pending challenge and its code',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'otp', type: 'string', example: '289521', nullable: true),
                    new OA\Property(property: 'challenge_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'purpose', type: 'string', enum: ['login', 'reauth'], example: 'login', nullable: true),
                    new OA\Property(property: 'for', type: 'string', format: 'email', example: 'super@guftagu.local', nullable: true),
                    new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'attempts', type: 'integer', example: 0, nullable: true),
                    new OA\Property(property: 'source', type: 'string', example: 'storage/logs/laravel.log (MAIL_MAILER=log)'),
                    new OA\Property(property: 'note', type: 'string'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 404, description: '`NOT_FOUND` — no pending challenge and nothing in the log; call login first', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
class DevPaths
{
}
