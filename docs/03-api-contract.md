# 03 — API Contract

← [02 Database Schema](02-database-schema.md) · next → [04 Epic Backlog](04-epic-backlog.md)

**Base URL:** `https://api.guftagu.com/api/v1`
**Auth:** Laravel Sanctum bearer tokens
**Docs:** OpenAPI 3 — Swagger UI at `/api/documentation`, raw document at `/docs` (SLA §5.1c). Locally: <http://127.0.0.1:8001/api/documentation>

Two consumers, two route groups, two middleware stacks:

| Group | Prefix | Consumer | Guard |
|---|---|---|---|
| **Mobile** | `/api/v1/…` | Flutter app | `auth:sanctum` + `user.active` |
| **Admin** | `/api/v1/admin/…` | Vue panel | `auth:sanctum-admin` + `permission:…` |

---

## 1. Contents

- [2. Conventions](#2-conventions)
- [3. Mobile — auth & account](#3-mobile--auth--account)
- [4. Mobile — rooms](#4-mobile--rooms)
- [5. Mobile — calls](#5-mobile--calls)
- [6. Mobile — gifting & wallet](#6-mobile--gifting--wallet)
- [7. Mobile — VIP, progression, rankings, events](#7-mobile--vip-progression-rankings-events)
- [8. Mobile — social, chat, agency, safety](#8-mobile--social-chat-agency-safety)
- [9. Admin — auth, access & permissions](#9-admin--auth-access--permissions)
- [10. Admin — users, rooms, moderation](#10-admin--users-rooms-moderation)
- [11. Admin — economy, gifts, VIP](#11-admin--economy-gifts-vip)
- [12. Admin — agency, events, CMS, reports](#12-admin--agency-events-cms-reports)
- [13. WebSocket events](#13-websocket-events)
- [14. Webhooks](#14-webhooks)
- [15. Error codes](#15-error-codes)
- [16. Rate limits](#16-rate-limits)

---

## 2. Conventions

### 2.1 Response envelope

Every response, success or failure, has the same shape. No exceptions.

```json
{
  "success": true,
  "message": "Gift sent",
  "data": { },
  "meta": { "request_id": "01J8X…", "timestamp": "2026-08-31T10:22:41Z" }
}
```

```json
{
  "success": false,
  "message": "Insufficient coin balance",
  "error": { "code": "INSUFFICIENT_BALANCE", "details": { "required": 5000, "available": 1200 } },
  "meta": { "request_id": "01J8X…", "timestamp": "2026-08-31T10:22:41Z" }
}
```

Validation failures return `422` with per-field detail:

```json
{ "success": false, "message": "Validation failed",
  "error": { "code": "VALIDATION_ERROR",
             "details": { "phone": ["The phone field is required."] } } }
```

### 2.2 Headers

| Header | Direction | Notes |
|---|---|---|
| `Authorization: Bearer <token>` | → | all authenticated calls |
| `Accept-Language: en \| hi` | → | drives localised strings (E.5a) |
| `X-Device-Id` | → | required on mobile; binds the token to a device |
| `X-App-Version` | → | mobile; drives force-upgrade responses |
| `X-Idempotency-Key` | → | required on gift send, order create, withdrawal request |
| `X-Request-Id` | ← | echoed for tracing |
| `X-RateLimit-Remaining` / `Retry-After` | ← | on throttled endpoints |

### 2.3 Pagination

Cursor for feeds and chat, offset for admin tables.

```
GET /rooms?cursor=eyJpZCI6MTIzfQ&limit=20
GET /admin/users?page=3&per_page=50&sort=-created_at&q=raj&status=active
```

```json
"meta": { "next_cursor": "eyJpZCI6MTQzfQ", "has_more": true }
"meta": { "current_page": 3, "per_page": 50, "total": 8421, "last_page": 169 }
```

Default 20, maximum 100. `sort` takes `-field` for descending.

### 2.4 Identifiers

The mobile API exposes **UUIDs only**. `{id}` in a mobile route always means the UUID. The admin API
may use numeric ids. Never leak a sequential id to the app.

---

## 3. Mobile — auth & account

**Epic D.1 · E.4**

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/auth/otp/send` | — | Send OTP. Body `{phone, country_code, purpose}`. Throttled 3/hr/number |
| POST | `/auth/otp/verify` | — | `{phone, country_code, otp, device}` → token + `is_new_user` |
| POST | `/auth/social` | — | `{provider: google\|apple, id_token, device}` → token |
| POST | `/auth/refresh` | refresh | Rotate the access token |
| POST | `/auth/logout` | ✓ | Revoke this device's token |
| GET | `/auth/me` | ✓ | Current user, profile, wallet summary, VIP, unread counts |
| DELETE | `/auth/account` | ✓ | DPDPA deletion request — 30-day grace, reversible until then |
| GET | `/profile` | ✓ | Own profile |
| PATCH | `/profile` | ✓ | `{display_name, bio, gender, date_of_birth, city}` — banned-word checked |
| POST | `/profile/avatar` | ✓ | multipart; re-encoded server-side |
| PATCH | `/profile/preferences` | ✓ | `{language, theme, privacy{}, notification_prefs{}}` (D.1c–d) |
| GET | `/profile/{uuid}` | ✓ | Another user — respects privacy and blocks (D.3c) |
| POST | `/profile/kyc` | ✓ | Submit KYC documents |
| GET | `/profile/kyc` | ✓ | KYC status |
| GET | `/app/config` | — | Feature flags, min supported version, CDN bases, Agora app id |
| GET | `/app/cms/{slug}` | — | terms · privacy · guidelines · about |

**`POST /auth/otp/verify` → 200**

```json
{ "success": true, "data": {
  "access_token": "…", "refresh_token": "…", "expires_in": 86400,
  "is_new_user": false,
  "user": { "uuid": "…", "guftagu_id": "GF8420156", "display_name": "Aarav",
            "avatar_url": "…", "level": 12, "vip_tier": 2, "agora_uid": 84201567 }
} }
```

---

## 4. Mobile — rooms

**Epic D.2 · E.1**

| Method | Path | Purpose |
|---|---|---|
| GET | `/rooms` | Explore. Filters `category`, `sort=trending\|new\|following`, `q` |
| GET | `/rooms/trending` | Top rooms from the Redis `rooms:live` ZSET |
| GET | `/rooms/categories` | Category list with live counts |
| POST | `/rooms` | Create. `{name, category_id, theme_id, visibility, password, seat_count, seat_layout, video_enabled, cover}` |
| GET | `/rooms/{uuid}` | Room detail before joining |
| GET | `/rooms/{uuid}/state` | **Full state snapshot** — seats, members, handraise, announcement. Called on join and after every reconnect |
| PATCH | `/rooms/{uuid}` | Owner edits name, cover, announcement, theme, password |
| POST | `/rooms/{uuid}/join` | `{password?}` → membership + RTC token. Rejects if banned or sanctioned |
| POST | `/rooms/{uuid}/leave` | |
| POST | `/rooms/{uuid}/close` | Owner closes |
| POST | `/rooms/{uuid}/rtc-token` | Mint or refresh an Agora token for the caller's current role |
| GET | `/rooms/{uuid}/members` | Paginated, with seat and role |
| POST | `/rooms/{uuid}/seats/{n}/take` | Take an empty seat |
| POST | `/rooms/{uuid}/seats/leave` | Vacate own seat |
| POST | `/rooms/{uuid}/seats/{n}/invite` | Host/co-host invites a user (D.2b) |
| POST | `/rooms/{uuid}/seats/{n}/lock` | Lock / unlock (D.2b) |
| POST | `/rooms/{uuid}/seats/{n}/mute` | Host mutes an occupant |
| POST | `/rooms/{uuid}/seats/{n}/kick` | Remove the occupant from the seat |
| PATCH | `/rooms/{uuid}/mic` | Self mute/unmute `{muted: bool}` |
| PATCH | `/rooms/{uuid}/camera` | Self camera on/off (D.5b) |
| POST | `/rooms/{uuid}/handraise` | Request to speak (D.2c) |
| DELETE | `/rooms/{uuid}/handraise` | Withdraw |
| GET | `/rooms/{uuid}/handraise` | Host views the queue |
| POST | `/rooms/{uuid}/handraise/{userUuid}/accept` | Host accepts → assigns a seat |
| POST | `/rooms/{uuid}/handraise/{userUuid}/reject` | |
| POST | `/rooms/{uuid}/cohost/{userUuid}` | Grant / revoke co-host |
| GET | `/rooms/{uuid}/messages` | Chat history, cursor-paginated |
| POST | `/rooms/{uuid}/messages` | `{type, body}` — banned-word filtered (A.5a) |
| POST | `/rooms/{uuid}/messages/{id}/pin` | D.2e |
| DELETE | `/rooms/{uuid}/messages/{id}` | Owner or host removes |
| POST | `/rooms/{uuid}/announcement` | D.2e |
| POST | `/rooms/{uuid}/invite-link` | Generate a shareable link (D.2e) |
| POST | `/rooms/{uuid}/ban/{userUuid}` | Room-level ban by host |
| GET | `/rooms/{uuid}/gifts` | Recent gift feed for this room |

**`GET /rooms/{uuid}/state` → 200** — the single most-called endpoint in the app.

```json
{ "success": true, "data": {
  "room": { "uuid": "…", "name": "Late Night Adda", "status": "live",
            "listener_count": 214, "video_enabled": false,
            "announcement": "Respect everyone 🙏", "theme": { "background_url": "…" } },
  "owner": { "uuid": "…", "display_name": "Zoya", "avatar_url": "…", "vip_tier": 3 },
  "seats": [
    { "seat_number": 1, "user": { "uuid": "…", "display_name": "Zoya", "frame_url": "…",
                                  "charm_level": 31 },
      "muted": false, "camera_on": false, "locked": false, "speaking": true },
    { "seat_number": 2, "user": null, "muted": false, "camera_on": false, "locked": true }
  ],
  "my": { "role": "listener", "seat_number": null, "muted": true,
          "can_take_seat": true, "handraise_status": null },
  "handraise_count": 3,
  "rtc": { "app_id": "…", "channel": "room_1042", "uid": 84201567,
           "token": "006…", "role": "subscriber", "expires_at": "2026-08-31T11:22:41Z" }
} }
```

---

## 5. Mobile — calls

**Epic D.5**

| Method | Path | Purpose |
|---|---|---|
| POST | `/calls` | Start. `{type: voice\|video, mode: one_to_one\|group, participants: [uuid]}` |
| POST | `/calls/{uuid}/accept` | |
| POST | `/calls/{uuid}/decline` | |
| POST | `/calls/{uuid}/cancel` | Caller cancels while ringing |
| POST | `/calls/{uuid}/end` | |
| POST | `/calls/{uuid}/rtc-token` | Agora token for the call channel |
| PATCH | `/calls/{uuid}/media` | `{camera_on, mic_on}` |
| POST | `/calls/{uuid}/invite` | Add a participant to a group call |
| GET | `/calls/history` | Paginated, with missed-call markers |

Ringing is delivered as `call.incoming` on `user.{uuid}` plus an FCM high-priority push, so the callee
is reached whether the app is foreground, background or killed.

---

## 6. Mobile — gifting & wallet

**Epic D.6 · E.3** — see the money rules in [02 §15](02-database-schema.md#15-money-integrity-rules).

| Method | Path | Purpose |
|---|---|---|
| GET | `/gifts` | Catalogue, filterable by `category`, `tier` |
| GET | `/gifts/categories` | |
| POST | `/rooms/{uuid}/gifts` | **Send a gift.** Requires `X-Idempotency-Key` |
| POST | `/calls/{uuid}/gifts` | Send inside a call |
| GET | `/wallet` | Balances, frozen amounts, lifetime totals |
| GET | `/wallet/coins/transactions` | Coin ledger, cursor-paginated |
| GET | `/wallet/diamonds/transactions` | Diamond ledger |
| GET | `/wallet/earnings` | Host earnings summary — daily, weekly, monthly (D.9b) |
| GET | `/recharge/packages` | Active packages (⚠ CI-01) |
| POST | `/recharge/orders` | Create an order → Razorpay `order_id`. Idempotent |
| POST | `/recharge/orders/{uuid}/verify` | Client-side confirmation; the webhook remains authoritative |
| GET | `/recharge/orders/{uuid}` | Order status — poll after payment |
| GET | `/invoices` | List |
| GET | `/invoices/{id}/download` | Signed PDF URL (E.3c) |
| GET | `/withdrawals/config` | Minimum, rate, fees, KYC requirement (⚠ CI-03) |
| POST | `/withdrawals` | Request. `{diamonds, method, payout_details}`. OTP-verified, idempotent |
| GET | `/withdrawals` | Own request history |
| POST | `/withdrawals/{uuid}/cancel` | While still `pending` |

**`POST /rooms/{uuid}/gifts`**

```json
// request
{ "gift_id": "…", "to_user": "…", "quantity": 1, "combo_group": "01J8X…" }
// X-Idempotency-Key: 5f2c…  (required)
```

```json
// 200
{ "success": true, "message": "Gift sent", "data": {
  "transaction_id": "…",
  "coin_balance": 48200,
  "gift": { "code": "ROSE", "animation_url": "…", "animation_type": "svga",
            "is_fullscreen": false, "duration_ms": 2400 },
  "combo_count": 7,
  "receiver": { "uuid": "…", "charm_points_gained": 450 }
} }
```

Failure modes: `INSUFFICIENT_BALANCE` (402) · `GIFT_UNAVAILABLE` (409) · `VIP_TIER_REQUIRED` (403) ·
`USER_SANCTIONED` (403) · `WALLET_FROZEN` (403). A repeated `X-Idempotency-Key` returns the original
`200` — it does not send a second gift.

---

## 7. Mobile — VIP, progression, rankings, events

**Epics D.7 · D.8**

| Method | Path | Purpose |
|---|---|---|
| GET | `/vip/tiers` | Tiers, pricing, privileges (⚠ CI-02) |
| POST | `/vip/purchase` | `{tier_id, duration: monthly\|quarterly\|yearly, pay_with: coins\|gateway}` |
| GET | `/vip/me` | Active subscription and expiry |
| POST | `/vip/auto-renew` | Toggle |
| GET | `/progression` | Account, wealth and charm levels with next-level thresholds |
| GET | `/badges` | Owned and available |
| GET | `/frames` | Owned; `POST /frames/{id}/equip` |
| POST | `/frames/{id}/purchase` | |
| GET | `/achievements` | Progress list; `POST /achievements/{id}/claim` |
| GET | `/checkin` | Streak state and today's reward |
| POST | `/checkin` | Claim today (D.7c) |
| GET | `/rankings` | `?board=wealth\|charm\|room\|agency&period=daily\|weekly\|monthly` — from Redis |
| GET | `/rankings/me` | Caller's rank on each board |
| GET | `/events` | `?status=live\|upcoming\|ended` |
| GET | `/events/{uuid}` | Detail, rules, rewards, leaderboard |
| POST | `/events/{uuid}/join` | Deducts entry cost if any |
| GET | `/events/{uuid}/leaderboard` | |
| POST | `/events/{uuid}/claim/{rewardId}` | |
| GET | `/lucky-draws/{uuid}` | Prize pool, `seed_hash`, result once drawn |

---

## 8. Mobile — social, chat, agency, safety

**Epics D.3 · D.4 · D.9**

| Method | Path | Purpose |
|---|---|---|
| GET | `/search` | `?q=&type=users\|rooms\|all` (D.3a) |
| GET | `/discover/recommendations` | Rooms and people, from follow graph + activity |
| POST | `/users/{uuid}/follow` · DELETE | Follow / unfollow (D.3b) |
| GET | `/users/{uuid}/followers` · `/following` | |
| GET | `/friends` · POST `/friends/{uuid}/request` · POST `/friends/{uuid}/accept` | |
| GET | `/users/{uuid}/visitors` | Profile visitors |
| GET | `/feed` · POST `/posts` · POST `/posts/{uuid}/like` · POST `/posts/{uuid}/comments` | D.3d — **descope lever #1** |
| GET | `/conversations` | DM list with unread counts |
| POST | `/conversations` | Start a direct or group conversation |
| GET | `/conversations/{uuid}/messages` | Cursor-paginated |
| POST | `/conversations/{uuid}/messages` | `{type, body, media}` — blocked-user checked |
| POST | `/conversations/{uuid}/read` | Mark read |
| POST | `/conversations/{uuid}/mute` | |
| GET | `/notifications` | In-app centre (E.2d) |
| POST | `/notifications/read-all` | |
| GET | `/notifications/unread-count` | |
| POST | `/devices/register` | Register / refresh the FCM token |
| GET | `/agencies` | Browse agencies open to hosts (D.9a) |
| POST | `/host/apply` | `{agency_id, intro_audio, experience}` |
| GET | `/host/status` | Application and approval state |
| GET | `/host/earnings` | Earnings and target progress (D.9b) |
| GET | `/host/targets` | Current period target |
| POST | `/users/{uuid}/block` · DELETE | D.9c |
| GET | `/blocks` | |
| POST | `/reports` | `{target_type, target_id, category, description, evidence[]}` (D.9c) |
| GET | `/support/faqs` | D.9d |
| POST | `/support/tickets` · GET `/support/tickets` · POST `/support/tickets/{uuid}/messages` | |

---

## 9. Admin — auth, access & permissions

**Epics A.1 · A.11 · E.4** — the escalation guard is specified in
[01 §5.3](01-architecture.md#53-the-escalation-guard).

| Method | Path | Permission | Purpose |
|---|---|---|---|
| POST | `/admin/auth/login` | — | `{email, password}` → MFA challenge if enabled (A.1a) |
| POST | `/admin/auth/mfa/verify` | — | `{challenge_id, otp}` → token |
| POST | `/admin/auth/logout` | ✓ | |
| GET | `/admin/auth/me` | ✓ | Profile, role, **effective permission list** — the panel renders from this |
| PATCH | `/admin/auth/profile` | ✓ | A.1b |
| POST | `/admin/auth/password` | ✓ | A.1b |
| GET | `/admin/roles` | `access.role_manage` | |
| POST/PATCH/DELETE | `/admin/roles/{id}` | `access.role_manage` | System roles are not deletable |
| GET | `/admin/permissions` | `access.permission_grant` | Full catalogue, grouped by module |
| GET | `/admin/permissions/grantable` | `access.permission_grant` | **Only what the caller may delegate** — the panel builds its grant UI from this |
| GET | `/admin/admins` | `access.admin_manage` | List panel users |
| POST | `/admin/admins` | `access.admin_manage` | Create Admin / Manager / Moderator |
| PATCH | `/admin/admins/{id}` | `access.admin_manage` | |
| POST | `/admin/admins/{id}/status` | `access.admin_manage` | Activate / suspend |
| GET | `/admin/admins/{id}/permissions` | `access.permission_grant` | Effective set with origin (role vs direct) |
| POST | `/admin/admins/{id}/permissions` | `access.permission_grant` | **Grant** |
| DELETE | `/admin/admins/{id}/permissions` | `access.permission_grant` | **Revoke** |
| POST | `/admin/admins/{id}/permissions/deny` | `access.permission_grant` | Explicit deny over a role grant |
| GET | `/admin/admins/{id}/permission-log` | `access.audit_view` | Grant history |
| POST | `/admin/auth/mfa/reauth` | ✓ | GFT-122 — request an OTP to confirm a high-risk grant |
| POST | `/admin/auth/mfa/reauth/verify` | ✓ | GFT-122 — satisfies the `high` risk-level grant requirement |
| POST | `/admin/auth/mfa/toggle/{roleKey}` | `settings.manage` | Enable/disable 2FA per sub-role (A.1d) |
| PATCH | `/admin/settings/session-timeout` | `settings.manage` | A.1c |
| GET | `/admin/dev/last-otp` | — | **`APP_ENV=local` only** — reads the pending MFA challenge and its code out of the mail log so Swagger "Try it out" can get past the OTP step. The route is registered inside an environment check, so it does not exist elsewhere |

**`POST /admin/admins/{id}/permissions`**

```json
// request
{ "permissions": ["moderation.mute_user", "moderation.ban_temp", "rooms.force_close"],
  "scope": { "room_categories": [3, 7] },
  "expires_at": "2026-12-31T23:59:59Z",
  "reason": "Night-shift moderator, music rooms only" }
```

```json
// 200
{ "success": true, "message": "3 permissions granted", "data": {
  "granted": ["moderation.mute_user", "moderation.ban_temp", "rooms.force_close"],
  "effective_count": 12 } }
```

```json
// 403 — the guard that makes delegation safe
{ "success": false, "message": "You cannot grant permissions you do not hold",
  "error": { "code": "PERMISSION_ESCALATION_DENIED",
             "details": { "ungranted": ["payouts.approve"] } } }
```

Other guard responses: `SELF_GRANT_DENIED` (403) · `DELEGATION_TARGET_DENIED` (403, e.g. a Manager
attempting to grant) · `MFA_REQUIRED` (403, granting a `high` risk-level permission).

---

## 10. Admin — users, rooms, moderation

**Epics A.2 · A.3 · A.4 · A.5 · B.1 · B.4 · C.1–C.5**

### Dashboard (A.2, B.1)

| Method | Path | Permission |
|---|---|---|
| GET | `/admin/dashboard/kpis` | `dashboard.view` — active users, live rooms, DAU/MAU (A.2a) |
| GET | `/admin/dashboard/revenue` | `dashboard.view` — recharge, gifting, VIP (A.2b) |
| GET | `/admin/dashboard/engagement` | `dashboard.view` — retention, session length (A.2c) |
| GET | `/admin/dashboard/scoped` | `dashboard.view` — Manager's assigned rooms/hosts/agencies (B.1a) |
| POST | `/admin/dashboard/export` | `dashboard.export` — queued (A.2d) |

Query params on all four: `from`, `to`, `granularity=day|week|month`.

### Users (A.3)

| Method | Path | Permission |
|---|---|---|
| GET | `/admin/users` | `users.view` — search by phone, guftagu_id, name; filter status, level, VIP |
| GET | `/admin/users/{id}` | `users.view` — profile, wallet, rooms, sanctions, devices |
| GET | `/admin/users/{id}/pii` | `users.view_pii` — unmasked phone/email; **writes an audit entry** |
| PATCH | `/admin/users/{id}` | `users.edit` |
| POST | `/admin/users/{id}/suspend` | `users.suspend` — `{reason, until}` (A.3c) |
| POST | `/admin/users/{id}/ban` | `users.ban` — `{reason}` |
| POST | `/admin/users/{id}/unban` | `users.ban` |
| POST | `/admin/users/{id}/kyc/verify` | `users.kyc_verify` (A.3b) |
| PATCH | `/admin/users/{id}/level` | `users.level_edit` |
| PATCH | `/admin/users/{id}/vip` | `users.vip_edit` |
| GET | `/admin/users/{id}/wallet` | `wallet.view` |
| POST | `/admin/users/{id}/wallet/credit` | `wallet.manual_credit` — `{currency, amount, note}` **note mandatory** (A.3d) |
| POST | `/admin/users/{id}/wallet/debit` | `wallet.manual_debit` |
| POST | `/admin/users/{id}/wallet/freeze` | `wallet.manual_debit` |
| GET | `/admin/users/{id}/transactions` | `wallet.ledger_view` |

### Rooms (A.4, C.1, C.2)

| Method | Path | Permission |
|---|---|---|
| GET | `/admin/rooms` | `rooms.view` |
| GET | `/admin/rooms/live` | `rooms.monitor_live` — real-time list with filters (A.4a, C.1a) |
| GET | `/admin/rooms/{id}` | `rooms.view` — participants and seat detail (C.1c) |
| POST | `/admin/rooms/{id}/join-silent` | `rooms.join_silent` — RTC token as an invisible subscriber (C.1b) |
| POST | `/admin/rooms/{id}/feature` | `rooms.feature` (A.4b) |
| POST | `/admin/rooms/{id}/pin` | `rooms.pin` |
| PATCH | `/admin/rooms/{id}/category` | `rooms.categorise` |
| POST | `/admin/rooms/{id}/close` | `rooms.force_close` — `{reason}` (A.4c, C.2b) |
| POST | `/admin/rooms/{id}/seats/{n}/lock` | `rooms.seat_lock` (C.2b) |
| POST | `/admin/rooms/{id}/warn` | `moderation.warn_user` — in-room warning (C.2c) |
| POST | `/admin/rooms/{id}/users/{userId}/mute` | `moderation.mute_user` (C.2a) |
| POST | `/admin/rooms/{id}/users/{userId}/kick` | `moderation.kick_user` |
| CRUD | `/admin/room-categories`, `/admin/room-themes` | `rooms.theme_manage` (A.4d) |

`join-silent` is a genuinely privileged capability: the Moderator joins the Agora channel as a
subscriber with an admin flag that suppresses the `member.joined` broadcast. It is audit-logged every
single time — silent to the room, never silent in the log.

### Moderation (A.5, C.3, C.4, C.5)

| Method | Path | Permission |
|---|---|---|
| GET | `/admin/reports` | `reports.view` — sorted by priority then age (C.3a) |
| GET | `/admin/reports/{id}` | `reports.view` — evidence, flagged media, target history (C.3b) |
| POST | `/admin/reports/{id}/assign` | `reports.assign` |
| POST | `/admin/reports/{id}/action` | `reports.action` — `{action, duration_minutes, note}` (C.3c) |
| POST | `/admin/reports/{id}/dismiss` | `reports.action` |
| POST | `/admin/reports/{id}/escalate` | `reports.escalate` — `{to_admin_id, note}` (C.5b) |
| GET | `/admin/sanctions` | `moderation.logs_view` |
| POST | `/admin/sanctions` | `moderation.ban_temp` / `ban_permanent` (C.4b) |
| DELETE | `/admin/sanctions/{id}` | `moderation.ban_permanent` — revoke |
| CRUD | `/admin/banned-words` | `moderation.bannedwords_manage` (A.5a, C.4a) |
| POST | `/admin/banned-words/import` | `moderation.bannedwords_manage` — CSV bulk |
| GET | `/admin/moderation/logs` | `moderation.logs_view` (A.5c, C.4c) |
| GET | `/admin/moderation/stats` | `moderation.logs_view` — per-moderator throughput (A.5c) |
| GET | `/admin/moderation/alerts` | `moderation.live` — critical queue (C.5a) |

---

## 11. Admin — economy, gifts, VIP

**Epics A.6 · A.7 · E.3**

| Method | Path | Permission |
|---|---|---|
| CRUD | `/admin/gifts` | `gifts.manage` (A.6a) |
| POST | `/admin/gifts/{id}/animation` | `gifts.manage` — upload Lottie/SVGA (⚠ CI-06) |
| CRUD | `/admin/gift-categories` | `gifts.category_manage` (A.6b) |
| POST | `/admin/gifts/{id}/limited-drop` | `gifts.drop_manage` — `{stock, from, to}` (A.6b) |
| CRUD | `/admin/vip-tiers` | `vip.manage` (A.6c, ⚠ CI-02) |
| CRUD | `/admin/frames`, `/admin/badges`, `/admin/entrance-effects` | `gifts.manage` (A.6d) |
| GET/PATCH | `/admin/economy/rates` | `economy.rates_manage` (A.7a, ⚠ CI-01) |
| CRUD | `/admin/economy/packages` | `economy.packages_manage` (A.7a) |
| CRUD | `/admin/economy/commission-slabs` | `economy.commission_manage` (A.7c, ⚠ CI-02) |
| GET | `/admin/economy/ledger` | `economy.ledger_view` — unified transaction ledger (A.7d) |
| GET | `/admin/economy/reconciliation` | `economy.reconcile` — gateway settlement vs `payments` (A.7d) |
| POST | `/admin/economy/reconciliation/run` | `economy.reconcile` |
| GET | `/admin/withdrawals` | `payouts.view` — filter by status (A.7b) |
| POST | `/admin/withdrawals/{id}/approve` | `payouts.approve` |
| POST | `/admin/withdrawals/{id}/reject` | `payouts.reject` — `{reason}`, returns frozen diamonds |
| POST | `/admin/payout-batches` | `payouts.batch_process` — build a batch from approved requests |
| POST | `/admin/payout-batches/{id}/process` | `payouts.batch_process` — export file / gateway payout |
| GET | `/admin/orders` · `/admin/payments` · `/admin/refunds` | `economy.ledger_view` |
| POST | `/admin/refunds` | `economy.reconcile` |
| GET | `/admin/invoices` | `economy.ledger_view` |

Withdrawal approval above a configurable threshold requires a **Super Admin** second approval — the
SLA's "approves high-risk actions such as … large payouts". The threshold lives in `settings`.

---

## 12. Admin — agency, events, CMS, reports

**Epics A.8 · A.9 · A.10 · B.2 · B.3 · B.5 · E.2**

| Method | Path | Permission |
|---|---|---|
| GET | `/admin/agencies` | `agency.view` |
| POST | `/admin/agencies/{id}/approve` · `/reject` | `agency.approve` (A.8a) |
| PATCH | `/admin/agencies/{id}` | `agency.edit` |
| GET | `/admin/agencies/{id}/performance` | `agency.view` (A.8c) |
| GET | `/admin/hosts` | `hosts.view` |
| POST | `/admin/hosts/{id}/approve` · `/reject` | `hosts.approve` (A.8a, B.2a) |
| CRUD | `/admin/host-targets` | `hosts.target_manage` (A.8b, B.2b) |
| GET | `/admin/hosts/{id}/earnings` | `hosts.earnings_view` (A.8c) |
| GET | `/admin/settlements` | `agency.settlement_process` |
| POST | `/admin/settlements` | `agency.view` — **Manager raises** (B.2c) |
| POST | `/admin/settlements/{id}/approve` | `agency.settlement_process` — **Admin approves** (A.8d) |
| POST | `/admin/settlements/{id}/pay` | `payouts.batch_process` |
| CRUD | `/admin/events` | `events.manage` (A.9a, B.3a) |
| POST | `/admin/events/{id}/approve` | `events.manage` — Manager-created events need approval |
| CRUD | `/admin/events/{id}/rewards` | `events.reward_manage` (A.9b) |
| POST | `/admin/lucky-draws/{id}/draw` | `events.manage` — publishes seed and result |
| CRUD | `/admin/ranking-rules` | `rankings.rules_manage` (A.9c) |
| POST | `/admin/rankings/{ruleKey}/payout` | `rankings.reward_payout` (A.9d) |
| CRUD | `/admin/banners` | `cms.banner_manage` (A.10a, B.3b) |
| POST | `/admin/banners/{id}/approve` | `cms.banner_manage` — Manager submissions |
| CRUD | `/admin/announcements` · `/admin/cms-pages` · `/admin/faqs` | `cms.announcement_manage`, `cms.page_manage` |
| POST | `/admin/broadcasts` | `cms.campaign_send` — push campaign (A.10a, E.2c) |
| POST | `/admin/broadcasts/{id}/send` | `cms.campaign_send` |
| GET | `/admin/broadcasts/{id}/stats` | `cms.campaign_send` |
| POST | `/admin/reports/generate` | `reports_export.*` — `{type, filters, format}`, queued (A.10b–c, B.5a) |
| GET | `/admin/reports/exports` | `reports_export.*` — download list |
| GET | `/admin/audit-logs` | `access.audit_view` — filter by actor, module, entity, date (A.10d) |
| GET | `/admin/support/tickets` | `users.view` (B.4a) |
| POST | `/admin/support/tickets/{id}/reply` · `/resolve` | `users.edit` |
| GET/PATCH | `/admin/settings` | `settings.view` / `settings.manage` |
| CRUD | `/admin/translations` | `settings.manage` (E.5a) |

---

## 13. WebSocket events

Reverb, Pusher protocol. Channel authorisation goes through the same permission gate as HTTP
([01 §4.3](01-architecture.md#43-channel-taxonomy)).

### `room.{uuid}` — presence

| Event | Payload |
|---|---|
| `member.joined` | `{user, listener_count}` |
| `member.left` | `{user_uuid, listener_count}` |
| `seat.updated` | `{seat_number, user, muted, camera_on, locked}` |
| `mic.toggled` | `{user_uuid, muted}` |
| `speaking.updated` | `{speaking: [uuid]}` — throttled to 500 ms |
| `chat.message` | `{id, user, type, body, created_at}` |
| `chat.message.deleted` | `{id}` |
| `chat.pinned` | `{message}` |
| `gift.sent` | `{sender, receiver, gift{animation_url, type, fullscreen, duration_ms}, quantity, combo_count}` |
| `handraise.updated` | `{count, latest_user}` |
| `entrance.effect` | `{user, effect{animation_url, duration_ms}}` |
| `room.announcement` | `{announcement}` |
| `room.closed` | `{reason, by: user\|admin}` |
| `user.muted` | `{user_uuid, by, duration}` |
| `user.kicked` | `{user_uuid, by, reason}` |
| `cohost.changed` | `{user_uuid, is_cohost}` |

### `user.{uuid}` — private

`call.incoming` · `call.accepted` · `call.declined` · `call.ended` · `notification.new` ·
`wallet.updated` · `follow.new` · `message.new` · `sanction.applied` · `vip.expired` ·
`withdrawal.status` · `host.approved`

### `admin.moderation` — private, `moderation.live`

`report.created` · `report.escalated` · `report.assigned` · `room.flagged` · `sanction.applied`

### `admin.dashboard` — private, `dashboard.view`

`kpi.tick` — throttled to 5 s

### `agency.{id}` — private

`host.joined` · `host.left` · `earning.updated` · `settlement.status`

---

## 14. Webhooks

### 14.1 Razorpay → `POST /webhooks/razorpay`

No auth middleware; `X-Razorpay-Signature` HMAC-verified against the endpoint secret before anything
in the payload is read. Handled events: `payment.captured`, `payment.failed`, `order.paid`,
`refund.processed`, `payout.processed`, `payout.failed`.

Contract: store the raw payload in `payment_webhooks`, return `200` within 2 s, process in a queued
job. Deduplicated on `event_id` — a replay is a no-op. Signature failure returns `400` and is logged
as a security event.

### 14.2 Agora → `POST /webhooks/agora`

Optional NCS callbacks for channel lifecycle, used to reconcile rooms whose host vanished without a
clean close. Verified by the Agora signature header.

### 14.3 MSG91 → `POST /webhooks/msg91`

Delivery receipts for OTP and transactional SMS. Updates `notifications.sent_at` and feeds the
delivery-failure alert.

---

## 15. Error codes

| HTTP | Code | Meaning |
|---|---|---|
| 400 | `BAD_REQUEST` | Malformed request |
| 401 | `UNAUTHENTICATED` | Missing or invalid token |
| 401 | `TOKEN_EXPIRED` | Refresh required |
| 401 | `DEVICE_MISMATCH` | Token presented from a different device — token revoked |
| 403 | `FORBIDDEN` | Authenticated but not permitted |
| 403 | `PERMISSION_DENIED` | Named permission missing |
| 403 | `PERMISSION_ESCALATION_DENIED` | Granting beyond one's own set |
| 403 | `DELEGATION_TARGET_DENIED` | Not allowed to grant to that role |
| 403 | `SELF_GRANT_DENIED` | Granting to oneself |
| 403 | `MFA_REQUIRED` | Re-authentication needed for a high-risk action |
| 403 | `USER_SANCTIONED` | Banned, suspended or muted |
| 403 | `WALLET_FROZEN` | Wallet administratively frozen |
| 403 | `KYC_REQUIRED` | Withdrawal without verified KYC |
| 403 | `VIP_TIER_REQUIRED` | Insufficient VIP tier |
| 403 | `BLOCKED_BY_USER` | Target has blocked the caller |
| 404 | `NOT_FOUND` | |
| 409 | `ROOM_FULL` / `SEAT_TAKEN` / `SEAT_LOCKED` | Room state conflict |
| 409 | `ALREADY_IN_ROOM` / `ALREADY_IN_CALL` | |
| 409 | `GIFT_UNAVAILABLE` | Inactive, out of stock, or outside its window |
| 409 | `DUPLICATE_REQUEST` | Idempotency key seen with different parameters |
| 409 | `ORDER_ALREADY_PAID` | |
| 402 | `INSUFFICIENT_BALANCE` | |
| 402 | `BELOW_MINIMUM_WITHDRAWAL` | |
| 422 | `VALIDATION_ERROR` | Field-level detail in `error.details` |
| 422 | `BANNED_WORD_DETECTED` | Content rejected by the filter |
| 423 | `ROOM_LOCKED` | Private room, wrong or missing password |
| 423 | `ACCOUNT_LOCKED` | Admin locked out after 5 failed logins (A.1a). `Retry-After` present |
| 426 | `APP_UPDATE_REQUIRED` | Below the minimum supported version |
| 429 | `RATE_LIMITED` | `Retry-After` header present |
| 500 | `SERVER_ERROR` | `request_id` returned for support |
| 502 | `GATEWAY_ERROR` | Razorpay / Agora / MSG91 upstream failure |
| 503 | `MAINTENANCE_MODE` | |

---

## 16. Rate limits

| Scope | Limit |
|---|---|
| OTP send | 3 / hour / phone · 10 / day / IP |
| OTP verify | 5 attempts / OTP, then invalidated |
| Login (admin) | 5 / min / IP, lockout for 15 min after 5 failures |
| Gift send | 20 / min / user |
| Room create | 5 / hour / user |
| Chat message | 10 / 10 s / user / room |
| DM send | 30 / min / user |
| Report submit | 10 / hour / user |
| Search | 30 / min / user |
| General mobile API | 120 / min / user |
| Admin API | 300 / min / admin |
| Export generation | 5 / hour / admin |

Redis-backed sliding windows. Exceeding a limit returns `429 RATE_LIMITED` with `Retry-After`.

---

## 17. Documentation & sync workflow

- **OpenAPI is generated, not hand-written.** Annotate controllers and Form Requests; CI regenerates
  the spec and fails the build if it drifts from the committed copy (SLA §5.1c).
- **This document is the contract.** Backend implements it, Flutter and Vue consume it. A change here
  is a change everywhere — no endpoint ships that is not in this file.
- **Claude Sync.** Per the global instructions, API contracts are shared between backend and frontend
  Claude sessions at `http://api.claudesync.aaibuzz.com/api/claude-sync` — post
  `API_CONTRACT_*.md` and append to `BACKEND_UPDATES.md` whenever an endpoint lands or changes.
  ⚠ **The project slug for Guftagu has not been set.** Confirm it before the first push and record it
  here and in the project `CLAUDE.md`; do not guess one.
