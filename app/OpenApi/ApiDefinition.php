<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * The OpenAPI 3 root definition — published at /api/documentation per docs/03 §"Docs"
 * (SLA §5.1c).
 *
 * swagger-php 6 dropped docblock annotations, so everything here is PHP 8 attributes.
 * This class holds no logic; it exists to carry the document-level metadata and the
 * reusable schemas every endpoint points at.
 */
#[OA\Info(
    version: '1.0.0',
    title: 'Guftagu Admin API',
    description: <<<'MD'
Voice-first social platform — **admin panel API**.

Two consumers, two route groups (docs/03): `/api/v1/…` for the Flutter app,
`/api/v1/admin/…` for the Vue panel. Only the admin group exists so far — the mobile
group lands with epic D.1.

### How to test a protected endpoint

1. `POST /admin/auth/login` with the seeded Super Admin — `super@guftagu.local` / `Guftagu@2026`.
2. MFA is on for that role, so you get a `challenge_id` and **no token**.
3. Fetch the code from **`GET /admin/dev/last-otp`** (local only), or from `storage/logs/laravel.log`.
4. `POST /admin/auth/mfa/verify` with the `challenge_id` and `otp` → you get a `token`.
5. Click **Authorize** at the top right, paste the token, and every locked endpoint works.

### Response envelope

Every response, success or failure, has the same shape — see the `Envelope` and
`ErrorEnvelope` schemas at the bottom of this page. `error.code` is always one of the
codes in docs/03 §15.

### Scoped accounts (B.1a, B.5a)

A direct permission grant may carry a **scope** — a list of agencies, room categories, or a
shift window (docs/02 §2.4). When one does, it constrains what that account can see
**everywhere**, not only on the screen the grant was named for: scope is treated as a fact
about the person, because an operator who scopes `agency.view` and forgets `hosts.view`
would otherwise have handed over every host on the platform.

Two things follow, and both are enforced server-side:

- **Lists are narrowed in SQL.** Counts, pagination totals and export row counts are all
  scoped, because hiding rows in the UI leaves the totals wrong and the API wide open.
- **A direct call for an out-of-scope id returns `403 OUT_OF_SCOPE`.** That code is
  deliberately distinct from `PERMISSION_DENIED`: "you lack this permission" and "you hold
  it, but not for that agency" send somebody looking in different places.

A scoped account also loses the platform-wide views outright rather than getting a filtered
version of them. Recharge revenue, DAU and total users cannot be attributed to an agency, so
`GET /admin/dashboard/kpis` returns a **different payload** for a scoped account — hosts,
earnings, targets and settlements within scope — and `/dashboard/revenue`,
`/dashboard/engagement` and the revenue report refuse with `OUT_OF_SCOPE`. Returning zeroes
there would read as an outage; returning the platform figures would leak them.

A Super Admin is never scoped.

### Session rules

Access tokens last 24 h, but a session also dies after **60 minutes idle** by default
(configurable at runtime, A.1c). After that any call returns `401 TOKEN_EXPIRED` and the
token is deleted — log in again.

### Rate limits

Login 5/min per IP and per email · MFA 10/min per IP · all other admin routes 300/min.
Exceeding one returns `429 RATE_LIMITED` with `Retry-After`.
MD,
    contact: new OA\Contact(name: 'AaiBuzz India Pvt. Ltd.', email: 'nadir.shaikh@aaibuzz.com')
)]
#[OA\Server(
    url: L5_SWAGGER_CONST_HOST,
    description: 'Local development — Laravel on port 8001 (8000 is the old FastAPI app)'
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    description: 'A Sanctum token from `POST /admin/auth/login`, or from `/admin/auth/mfa/verify` when MFA applies. Paste the raw token — Swagger adds the `Bearer ` prefix for you.'
)]
#[OA\Tag(name: 'Auth', description: 'Login, MFA, profile, password — epic A.1')]
#[OA\Tag(name: 'Security Policy', description: 'Session timeout and per-role 2FA — A.1c, A.1d. Requires `settings.manage`')]
#[OA\Tag(name: 'Panel Users', description: 'Create and manage Admin / Manager / Moderator accounts — GFT-127. Requires `access.admin_manage`')]
#[OA\Tag(name: 'Roles', description: 'Role CRUD and baselines. Requires `access.role_manage`; system roles cannot be deleted')]
#[OA\Tag(name: 'Permissions', description: 'The catalogue, and the caller’s delegable subset — GFT-119. Requires `access.permission_grant`')]
#[OA\Tag(name: 'Delegation', description: 'Grant, revoke and deny behind the escalation guard — epic A.11')]
#[OA\Tag(name: 'Dashboard', description: 'KPIs, revenue and retention — epic A.2. Reads the daily_stats rollup, never the ledgers')]
#[OA\Tag(name: 'Users', description: 'App-user management — epic A.3. Phone and email are masked everywhere except the audited PII endpoint')]
#[OA\Tag(name: 'Wallet', description: 'Balances, the immutable ledger, and manual adjustments — A.3d. Governed by the money-integrity rules in docs/02 §15')]
#[OA\Tag(name: 'Events', description: 'Events, tournaments and provable-fairness lucky draws — epic A.9a/b')]
#[OA\Tag(name: 'Rankings', description: 'Leaderboards, snapshots and idempotent reward payouts — A.9c/d')]
#[OA\Tag(name: 'Economy', description: 'Rates, packages, commission and the ledger — epic A.7a/c/d. Rational rates, integer basis points')]
#[OA\Tag(name: 'Payouts', description: 'The withdrawal review queue — A.7b. Freeze on request, pay or return on decision')]
#[OA\Tag(name: 'Store', description: 'Gifts, categories and limited drops — epic A.6a/b. Writes flush the app catalogue cache')]
#[OA\Tag(name: 'VIP', description: 'VIP tiers and the cosmetics they gate — A.6c/d. Prices are integer paise')]
#[OA\Tag(name: 'Support', description: 'The support inbox, SLA timers and escalation — epic B.4')]
#[OA\Tag(name: 'Content', description: 'Banners, announcements, CMS pages and FAQs — A.10a. Scheduled content derives its visibility')]
#[OA\Tag(name: 'Campaigns', description: 'Broadcast campaigns — A.10a. Audience preview before sending')]
#[OA\Tag(name: 'Reports', description: 'The report centre — A.10b/c. Revenue reads the ledger, exports stream')]
#[OA\Tag(name: 'Audit', description: 'The append-only admin audit trail — A.10d')]
#[OA\Tag(name: 'Agencies', description: 'Agency onboarding, approval and performance — A.8a, A.8c')]
#[OA\Tag(name: 'Hosts', description: 'Host applications, contracts, targets and daily earnings — A.8a–c')]
#[OA\Tag(name: 'Settlements', description: 'Agency settlement batches — A.8d. Idempotent generation and payment')]
#[OA\Tag(name: 'Moderation', description: 'The content filter, the reports queue and moderator oversight — epic A.5, C.3–C.5')]
#[OA\Tag(name: 'Rooms', description: 'Room monitoring and enforcement — epic A.4. Force-close lands in both the audit and moderation logs')]
#[OA\Tag(name: 'Room catalogue', description: 'Categories and themes the app offers — A.4d. Readable with rooms.view, editable with rooms.theme_manage')]
#[OA\Tag(name: 'Dev Helpers', description: 'Local-environment conveniences. These routes do not exist outside APP_ENV=local')]

// ----------------------------------------------------------------- reusable schemas

#[OA\Schema(
    schema: 'Meta',
    properties: [
        new OA\Property(property: 'request_id', type: 'string', example: '01M1BXMQQFMPZ416SD0CA7YMTF', description: 'Echoed as the X-Request-Id response header'),
        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time', example: '2026-08-31T12:45:24Z'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'Envelope',
    title: 'Success envelope',
    description: 'docs/03 §2.1 — every successful response has this shape.',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'OK'),
        new OA\Property(property: 'data', description: 'Endpoint-specific payload', nullable: true),
        new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'ErrorEnvelope',
    title: 'Error envelope',
    description: 'docs/03 §2.1 — every failure. `error.code` is one of the codes in docs/03 §15.',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'message', type: 'string', example: 'You do not have permission to perform this action'),
        new OA\Property(property: 'error', type: 'object', properties: [
            new OA\Property(property: 'code', type: 'string', example: 'PERMISSION_DENIED'),
            new OA\Property(property: 'details', type: 'object', description: 'Field errors, or context for the refusal', nullable: true),
        ]),
        new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PaginationMeta',
    description: 'docs/03 §2.3 — offset pagination for admin tables.',
    properties: [
        new OA\Property(property: 'request_id', type: 'string'),
        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'per_page', type: 'integer', example: 20),
        new OA\Property(property: 'total', type: 'integer', example: 84),
        new OA\Property(property: 'last_page', type: 'integer', example: 5),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'RoleRef',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 4),
        new OA\Property(property: 'key', type: 'string', enum: ['super_admin', 'admin', 'manager', 'moderator'], example: 'moderator'),
        new OA\Property(property: 'name', type: 'string', example: 'Moderator'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AdminProfile',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Super Admin'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'super@guftagu.local'),
        new OA\Property(property: 'phone', type: 'string', example: '+919876543210', nullable: true),
        new OA\Property(property: 'avatar_url', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'suspended'], example: 'active'),
        new OA\Property(property: 'mfa_enabled', type: 'boolean', example: true),
        new OA\Property(property: 'session_timeout_minutes', type: 'integer', description: 'Per-account override; null means use the platform default', example: null, nullable: true),
        new OA\Property(property: 'role', ref: '#/components/schemas/RoleRef'),
        new OA\Property(property: 'last_login_at', type: 'string', format: 'date-time', nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PermissionItem',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 57),
        new OA\Property(property: 'key', type: 'string', example: 'rooms.force_close'),
        new OA\Property(property: 'action', type: 'string', example: 'force_close'),
        new OA\Property(property: 'name', type: 'string', example: 'Force-close a room'),
        new OA\Property(property: 'risk_level', type: 'string', enum: ['low', 'medium', 'high'], description: 'Granting a `high` permission requires MFA re-entry (GFT-122)', example: 'high'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'ModuleGroup',
    properties: [
        new OA\Property(property: 'module', type: 'string', example: 'rooms'),
        new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(ref: '#/components/schemas/PermissionItem')),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'GrantScope',
    title: 'Grant scope (GFT-120)',
    description: 'Narrows a direct grant. Absent or empty means unrestricted. A scope key that is present but not satisfied by the call context is a refusal, not a pass.',
    properties: [
        new OA\Property(property: 'room_categories', type: 'array', items: new OA\Items(type: 'integer'), example: [3, 7]),
        new OA\Property(property: 'agencies', type: 'array', items: new OA\Items(type: 'integer'), example: [12]),
        new OA\Property(property: 'shift', type: 'object', description: 'May cross midnight — 18:00 → 02:00 is a valid night shift.', properties: [
            new OA\Property(property: 'from', type: 'string', example: '18:00'),
            new OA\Property(property: 'to', type: 'string', example: '02:00'),
            new OA\Property(property: 'tz', type: 'string', example: 'Asia/Kolkata'),
        ]),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'UserRow',
    description: 'An app user as the panel sees them. Phone and email are always masked here.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', description: 'The only id the mobile API exposes'),
        new OA\Property(property: 'guftagu_id', type: 'string', example: 'GF8420100'),
        new OA\Property(property: 'display_name', type: 'string', example: 'Aarav Sharma', nullable: true),
        new OA\Property(property: 'avatar_url', type: 'string', nullable: true),
        new OA\Property(property: 'country', type: 'string', example: 'India', nullable: true),
        new OA\Property(property: 'phone_masked', type: 'string', example: '+91 ••••••21', nullable: true),
        new OA\Property(property: 'email_masked', type: 'string', example: 'aa•••••@example.com', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'suspended', 'banned', 'deleted']),
        new OA\Property(property: 'kyc_status', type: 'string', enum: ['none', 'pending', 'verified', 'rejected']),
        new OA\Property(property: 'coin_balance', type: 'integer', example: 12500),
        new OA\Property(property: 'diamond_balance', type: 'integer', example: 3200),
        new OA\Property(property: 'wallet_frozen', type: 'boolean', example: false),
        new OA\Property(property: 'last_active_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'EventRow',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'type', type: 'string', enum: ['event', 'tournament', 'lucky_draw']),
        new OA\Property(property: 'title_en', type: 'string', example: 'Diwali Gifting Marathon'),
        new OA\Property(property: 'title_hi', type: 'string', nullable: true),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'banner_url', type: 'string', nullable: true),
        new OA\Property(property: 'entry_type', type: 'string', enum: ['free', 'coins', 'invite']),
        new OA\Property(property: 'entry_cost', type: 'integer'),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'scheduled', 'cancelled'], description: 'The operator\'s intent'),
        new OA\Property(property: 'phase', type: 'string', enum: ['draft', 'upcoming', 'live', 'ended', 'cancelled'], description: 'What is true right now — derived from the clock, never written'),
        new OA\Property(property: 'max_participants', type: 'integer', nullable: true),
        new OA\Property(property: 'is_featured', type: 'boolean'),
        new OA\Property(property: 'participant_count', type: 'integer'),
        new OA\Property(property: 'created_by', type: 'string', nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'WithdrawalRow',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'user', type: 'object', nullable: true),
        new OA\Property(property: 'diamonds', type: 'integer', example: 10000),
        new OA\Property(property: 'gross_paise', type: 'integer', example: 500000),
        new OA\Property(property: 'commission_paise', type: 'integer'),
        new OA\Property(property: 'tds_paise', type: 'integer'),
        new OA\Property(property: 'net_paise', type: 'integer', example: 500000),
        new OA\Property(property: 'net_rupees', type: 'number', description: 'Derived for display; paise are the truth', example: 5000),
        new OA\Property(property: 'rate', type: 'string', description: 'The rate this request was PRICED at, not today\'s', example: '50/1'),
        new OA\Property(property: 'method', type: 'string', enum: ['bank', 'upi']),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'pending_super_approval', 'approved', 'rejected', 'processing', 'paid', 'failed', 'reverted']),
        new OA\Property(property: 'is_open', type: 'boolean', description: 'Still awaiting a decision'),
        new OA\Property(property: 'needs_super_admin', type: 'boolean', description: 'At or above the high-value threshold'),
        new OA\Property(property: 'requested_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'reviewed_by', type: 'string', nullable: true),
        new OA\Property(property: 'second_approved_by', type: 'string', nullable: true),
        new OA\Property(property: 'rejection_reason', type: 'string', nullable: true),
        new OA\Property(property: 'utr', type: 'string', nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'GiftRow',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'code', type: 'string', example: 'sports_car'),
        new OA\Property(property: 'name_en', type: 'string', example: 'Sports Car'),
        new OA\Property(property: 'name_hi', type: 'string', nullable: true),
        new OA\Property(property: 'category', type: 'object', nullable: true),
        new OA\Property(property: 'tier', type: 'string', enum: ['basic', 'premium', 'luxury', 'legendary']),
        new OA\Property(property: 'coin_price', type: 'integer', example: 9999),
        new OA\Property(property: 'diamond_value', type: 'integer', description: 'What the receiver earns', example: 5000),
        new OA\Property(property: 'thumbnail_url', type: 'string', nullable: true),
        new OA\Property(property: 'animation_url', type: 'string', nullable: true),
        new OA\Property(property: 'animation_type', type: 'string', enum: ['lottie', 'svga', 'mp4'], nullable: true),
        new OA\Property(property: 'duration_ms', type: 'integer', nullable: true),
        new OA\Property(property: 'is_fullscreen', type: 'boolean'),
        new OA\Property(property: 'max_combo', type: 'integer', description: '1 when combos are disabled'),
        new OA\Property(property: 'vip_tier', type: 'object', nullable: true),
        new OA\Property(property: 'is_limited', type: 'boolean'),
        new OA\Property(property: 'stock', type: 'integer', description: 'null = unlimited, 0 = sold out', nullable: true),
        new OA\Property(property: 'available_from', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'available_to', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'state', type: 'object', description: 'The three separate reasons a gift may not be sellable', properties: [
            new OA\Property(property: 'available', type: 'boolean'),
            new OA\Property(property: 'sold_out', type: 'boolean'),
            new OA\Property(property: 'in_window', type: 'boolean'),
        ]),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'VipTierRow',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'level', type: 'integer', example: 3),
        new OA\Property(property: 'name_en', type: 'string', example: 'VIP Gold'),
        new OA\Property(property: 'name_hi', type: 'string', nullable: true),
        new OA\Property(property: 'badge_url', type: 'string', nullable: true),
        new OA\Property(property: 'frame_url', type: 'string', nullable: true),
        new OA\Property(property: 'monthly_price_paise', type: 'integer', description: 'Paise — the stored truth', example: 99900),
        new OA\Property(property: 'quarterly_price_paise', type: 'integer'),
        new OA\Property(property: 'yearly_price_paise', type: 'integer'),
        new OA\Property(property: 'coin_price', type: 'integer'),
        new OA\Property(property: 'monthly_rupees', type: 'number', description: 'Derived for display only', example: 999),
        new OA\Property(property: 'privileges', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'RoomRow',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'room_code', type: 'string', example: 'RM001000'),
        new OA\Property(property: 'name', type: 'string', example: 'Late Night Ghazals'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'cover_url', type: 'string', nullable: true),
        new OA\Property(property: 'visibility', type: 'string', enum: ['public', 'private']),
        new OA\Property(property: 'status', type: 'string', enum: ['live', 'idle', 'closed', 'force_closed']),
        new OA\Property(property: 'category', type: 'object', nullable: true),
        new OA\Property(property: 'owner', type: 'object', nullable: true),
        new OA\Property(property: 'seat_count', type: 'integer', example: 9),
        new OA\Property(property: 'seat_layout', type: 'string', enum: ['classic', 'party', 'podium', 'dating']),
        new OA\Property(property: 'video_enabled', type: 'boolean'),
        new OA\Property(property: 'listener_count', type: 'integer', description: 'Denormalised from Redis; recent, not live'),
        new OA\Property(property: 'peak_listeners', type: 'integer'),
        new OA\Property(property: 'diamonds', type: 'integer'),
        new OA\Property(property: 'is_pinned', type: 'boolean'),
        new OA\Property(property: 'is_featured', type: 'boolean', description: 'EFFECTIVE state — false once featured_until has lapsed'),
        new OA\Property(property: 'featured_flag', type: 'boolean', description: 'The stored column, which may still be true after expiry'),
        new OA\Property(property: 'featured_until', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'WalletSummary',
    description: 'Integer counts throughout — never floats (docs/02 §15 rule 1).',
    properties: [
        new OA\Property(property: 'coin_balance', type: 'integer', example: 12500),
        new OA\Property(property: 'diamond_balance', type: 'integer', example: 3200),
        new OA\Property(property: 'frozen_coins', type: 'integer', example: 0),
        new OA\Property(property: 'frozen_diamonds', type: 'integer', example: 0),
        new OA\Property(property: 'available_coins', type: 'integer', description: 'Balance minus anything held'),
        new OA\Property(property: 'available_diamonds', type: 'integer'),
        new OA\Property(property: 'lifetime_coins_purchased', type: 'integer'),
        new OA\Property(property: 'lifetime_coins_spent', type: 'integer'),
        new OA\Property(property: 'lifetime_diamonds_earned', type: 'integer'),
        new OA\Property(property: 'is_frozen', type: 'boolean', description: 'Admin freeze — blocks the user, not the admin'),
        new OA\Property(property: 'version', type: 'integer', description: 'Optimistic-lock counter'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'LedgerRow',
    description: 'One immutable ledger entry. `balance_before`/`balance_after` are the audit anchor that makes drift detectable.',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'direction', type: 'string', enum: ['credit', 'debit']),
        new OA\Property(property: 'amount', type: 'integer', description: 'Always positive; direction carries the sign', example: 1000),
        new OA\Property(property: 'signed_amount', type: 'integer', example: -250),
        new OA\Property(property: 'balance_before', type: 'integer'),
        new OA\Property(property: 'balance_after', type: 'integer'),
        new OA\Property(property: 'type', type: 'string', example: 'admin_credit'),
        new OA\Property(property: 'note', type: 'string', description: 'Mandatory on admin adjustments', nullable: true),
        new OA\Property(property: 'performed_by', type: 'string', description: 'The admin who made a manual adjustment', nullable: true),
        new OA\Property(property: 'is_adjustment', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'EffectivePermissionRow',
    description: 'One row of the effective-permission viewer (GFT-126).',
    properties: [
        new OA\Property(property: 'key', type: 'string', example: 'moderation.mute_user'),
        new OA\Property(property: 'module', type: 'string', example: 'moderation'),
        new OA\Property(property: 'action', type: 'string', example: 'mute_user'),
        new OA\Property(property: 'risk_level', type: 'string', enum: ['low', 'medium', 'high']),
        new OA\Property(
            property: 'origin',
            type: 'string',
            enum: ['super_admin', 'role', 'direct_grant', 'role_and_direct', 'denied_over_role', 'denied_direct'],
            description: 'Where the permission comes from. The two `denied_*` values mean it is NOT effective — the row is shown so an operator can see why it is missing.',
            example: 'direct_grant'
        ),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'scope', ref: '#/components/schemas/GrantScope', nullable: true),
    ],
    type: 'object'
)]
class ApiDefinition
{
}
