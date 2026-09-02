# Manual test — Module 1: Auth + RBAC

Covers epics **A.1** (GFT-001…012) and **A.11** (GFT-114…128), plus the E.4a parts they touch.

Everything below has already been verified once end-to-end over real HTTP, and is pinned by 48
automated tests (`php artisan test`). This is the script for *your* pass.

---

## 0. Before you start

| Thing | Value |
|---|---|
| API base | `http://127.0.0.1:8001/api/v1/admin` |
| Dev database | `guftagu_laravel` |
| Test database | `guftagu_laravel_test` (wiped by every test run) |
| Redis | `127.0.0.1:6379`, cache on **db 1** |

> **Port 8001, not 8000.** The old FastAPI app is still running on 8000 (`server: uvicorn`).
> Laravel could not bind there. Nothing was changed about the FastAPI stack — its database
> `guftagu` is also untouched, and the Laravel build uses `guftagu_laravel`.

Start the API:

```bash
cd C:/xampp/htdocs/Guftagoo/backend
php artisan serve --host=127.0.0.1 --port=8001
```

Reset to a clean, known state at any time:

```bash
php artisan migrate:fresh --seed --force && php artisan cache:clear
```

### Seeded accounts

Only the Super Admin is seeded. Everything else you create through the API.

| Role | Email | Password |
|---|---|---|
| Super Admin | `super@guftagu.local` | `Guftagu@2026` |

Change these via `SUPER_ADMIN_EMAIL` / `SUPER_ADMIN_PASSWORD` in `.env`.

### The OTP

`ADMIN_MFA_STATIC_OTP=123456` in `.env` pins every admin OTP — login and high-risk re-auth — to
**`123456`** for testing. It is ignored unless `APP_ENV=local`: `AdminAuthService::nextOtp()` checks
the environment *before* reading the value, discards anything that is not six digits, and logs a
warning on every use. Covered by `a_static_otp_is_used_in_local`,
`a_static_otp_is_ignored_outside_local` and `a_malformed_static_otp_falls_back_to_a_random_code`.

Clear that variable to test with real random codes. `MAIL_MAILER=log` writes every OTP to
`storage/logs/laravel.log` rather than emailing it, so read it either way:

```bash
# from the API (local only) — also returns the pending challenge_id
curl -s http://127.0.0.1:8001/api/v1/admin/dev/last-otp

# or straight from the log
grep -oE "^# [0-9]{6}$" storage/logs/laravel.log | tail -1
```

---

## 0b. Swagger UI — the fastest way to run all of this

**<http://127.0.0.1:8001/api/documentation>** · raw OpenAPI 3 document at `/docs`

All 28 admin endpoints are documented with request bodies, every response code, and the
`error.code` you should expect for each refusal. It is the same interactive surface as the old
FastAPI `/docs` page.

To test a locked endpoint:

1. **`POST /admin/auth/login`** → Try it out → Execute. You get a `challenge_id`, no token.
2. **`GET /admin/dev/last-otp`** → Execute. Copy `otp` (and the `challenge_id` it echoes back).
3. **`POST /admin/auth/mfa/verify`** → paste both → Execute. Copy the `token`.
4. Click **Authorize** (top right), paste the raw token, Authorize, Close.
5. Every 🔒 endpoint now works.

The document regenerates on every page load (`L5_SWAGGER_GENERATE_ALWAYS=true`), so edits to
`app/OpenApi/` show up on refresh. Set it to `false` outside local.

> The operations are defined in `app/OpenApi/Paths/*.php`, not on the controller methods. Nothing
> in PHP links the two, so `tests/Feature/Admin/OpenApiDocumentTest.php` does: it fails the build
> if a route gains no docs, if docs describe a route that no longer exists, if a `$ref` points at
> an undefined schema, or if a protected operation forgets to declare `bearerAuth`.

---

## 1. A.1a — login and MFA

| # | Step | Expect |
|---|---|---|
| 1.1 | `POST /auth/login` with the correct password | `200`, `data.mfa_required: true`, a `challenge_id`, **no token**, `sent_to` masked as `su•••@guftagu.local` |
| 1.2 | `POST /auth/mfa/verify` with the OTP from the log | `200` with `token`, `expires_at`, `admin` |
| 1.3 | Re-send the **same** OTP | `400 BAD_REQUEST` — "already been used" |
| 1.4 | Verify with a wrong OTP 5× | each `401`, `attempts_left` counting down |
| 1.5 | Then verify with the **correct** OTP | `429` — the challenge is burnt |
| 1.6 | Wait past 10 minutes, then verify | `400` — expired |
| 1.7 | Log in with a wrong password 5× | each `401 UNAUTHENTICATED`, message identical to an unknown email |
| 1.8 | 6th attempt, **correct** password | `423 ACCOUNT_LOCKED`, integer `Retry-After`, `locked_until` |
| 1.9 | Check `audit_logs` | a row `admin.login_locked` |

> The login route is throttled at 5/min/IP, so a 6th HTTP call inside the same minute may return
> `429 RATE_LIMITED` before the lockout can answer `423`. Both are correct refusals — wait a minute
> between step 1.7 and 1.8 to see the lockout itself.

**Streak clearing:** 4 wrong passwords, then the right one, then 4 more wrong ones must *not* lock
the account. A verified password resets the streak (recorded as `password_ok_mfa_pending`), because
the lockout guards the password and the OTP stage has its own limits.

## 2. A.1b — profile and password

| # | Step | Expect |
|---|---|---|
| 2.1 | `PATCH /auth/profile` with `name`, `phone` | `200`, values changed, `admin.profile_update` audited |
| 2.2 | `POST /auth/password` **without** `current_password` | `422 VALIDATION_ERROR` |
| 2.3 | With a wrong `current_password` | `422`, field-level detail |
| 2.4 | With the correct one, from device A, while device B is also logged in | `200`; **A still works, B returns `401`** |

## 3. A.1c — session timeout

| # | Step | Expect |
|---|---|---|
| 3.1 | `PATCH /settings/session-timeout` `{"minutes":30}` as Super Admin | `200` |
| 3.2 | Log in again, check `GET /auth/me` → `data.session.idle_timeout_minutes` | `30` |
| 3.3 | Idle past the window, then any request | `401 TOKEN_EXPIRED`, and the token is **deleted** |
| 3.4 | Same token again | `401 UNAUTHENTICATED` |
| 3.5 | Set a per-account `session_timeout_minutes` via `PATCH /admins/{id}` | the account override wins over the platform default |

To avoid waiting 30 minutes, drop the marker directly — the effect is identical to real inactivity:

```bash
php artisan tinker --execute="App\Http\Middleware\EnforceIdleTimeout::forget(<token_id>);"
```

The marker is a Redis key whose TTL *is* the timeout. Verify it is genuinely set (note **db 1**):

```bash
php artisan tinker --execute='$r=new Predis\Client(["host"=>"127.0.0.1","port"=>6379,"database"=>1]);
foreach($r->keys("*admin:session*") as $k) echo $k." ttl=".$r->ttl($k).PHP_EOL;'
```

## 4. A.1d — 2FA per sub-role

| # | Step | Expect |
|---|---|---|
| 4.1 | `POST /auth/mfa/toggle/moderator` `{"enabled":false}` as Super Admin | `200`, `mfa_required: false` |
| 4.2 | Log a Moderator in | `mfa_required: false`, token issued immediately, no OTP email |
| 4.3 | Re-enable, log in again | challenge issued again |
| 4.4 | Try 4.1 as a Manager | `403 PERMISSION_DENIED` (needs `settings.manage`) |
| 4.5 | Check `audit_logs` | `settings.mfa_toggle` with actor, before and after |

## 5. A.11 — the escalation guard

Set up: create an Admin, a Manager and a Moderator via `POST /admins`.

| # | Step | Expect |
|---|---|---|
| 5.1 | Super Admin denies `payouts.approve` to the Admin | needs MFA re-auth first (see §6), then `200`; Admin's `effective_count` drops 76 → 75 |
| 5.2 | **That Admin** grants `payouts.approve` to the Moderator, by direct API call | `403 PERMISSION_ESCALATION_DENIED`, `details.ungranted: ["payouts.approve"]` |
| 5.3 | Check the Moderator's set and `admin_user_permission` | unchanged — **nothing persisted** |
| 5.4 | Check `audit_logs` | `permission.grant_refused` naming actor, target and attempt |
| 5.5 | Grant a mix of one held and one unheld key | the **whole call** fails; the held one is not granted either |
| 5.6 | Anyone grants to themselves — Super Admin included | `403 SELF_GRANT_DENIED` |
| 5.7 | Admin grants to another Admin, or to the Super Admin | `403 DELEGATION_TARGET_DENIED` |
| 5.8 | Manager (no `access.permission_grant`) attempts a grant | `403 PERMISSION_DENIED` — the route gate refuses first |
| 5.9 | Grant `access.permission_grant` to the Manager, then retry 5.8 | `403 DELEGATION_TARGET_DENIED` — a Manager may never delegate |

> **On 5.8 vs 5.9:** docs/04 A.11 says a Manager attempting a grant returns
> `DELEGATION_TARGET_DENIED`. With the default Manager baseline the stricter route gate answers
> `PERMISSION_DENIED` first, so the documented code only appears once the Manager actually holds
> `access.permission_grant` (5.9). Both refuse correctly; the codes differ. Flagged rather than
> papered over — worth a one-line clarification in docs/04 if you want the literal wording to match.

## 6. GFT-122 — MFA re-entry for high-risk grants

| # | Step | Expect |
|---|---|---|
| 6.1 | Grant a `high` risk key (e.g. `payouts.approve`, `moderation.ban_permanent`) with no fresh re-auth | `403 MFA_REQUIRED`, `details.high_risk` lists the offending keys |
| 6.2 | `POST /auth/mfa/reauth` | `200` with a `challenge_id`; a second OTP in the log |
| 6.3 | `POST /auth/mfa/reauth/verify` | `200`, `confirmed_for_minutes: 5` |
| 6.4 | Retry 6.1 within 5 minutes | `200` |
| 6.5 | Grant a `medium` key with no re-auth | `200` — only `high` requires it |
| 6.6 | Verify a re-auth challenge belonging to a *different* admin | `403` |

## 7. GFT-115/118 — the resolver and cache

| # | Step | Expect |
|---|---|---|
| 7.1 | Grant `moderation.mute_user` to a Moderator who is **already logged in** | their existing token sees it on the very next `GET /auth/me` |
| 7.2 | Revoke it | gone on the very next request — no 300 s lag |
| 7.3 | `PATCH /roles/{moderator_role_id}` changing the baseline | every holder of that role is flushed |
| 7.4 | `POST /admins/{id}/permissions/deny` for a key in the role baseline | removed from the effective set; the viewer shows `origin: denied_over_role` |
| 7.5 | `DELETE …/permissions` for a key held only via the role | `revoked: []` and `still_held_via_role` explains it — revoke ≠ deny |
| 7.6 | Insert a grant with `expires_at` in the past | never effective, even before the expiry job runs |
| 7.7 | `GET /auth/me` as Super Admin | all **79** keys |

## 8. GFT-120 — scoped grants

Grant `moderation.mute_user` scoped to `{"room_categories":[3],"shift":{"from":"18:00","to":"02:00","tz":"Asia/Kolkata"}}`.

| # | Check | Expect |
|---|---|---|
| 8.1 | `ScopeGate` with `room_category: 3` | allowed |
| 8.2 | with `room_category: 5` | `out_of_category_scope` |
| 8.3 | with no category supplied | `out_of_category_scope` — **fails closed** |
| 8.4 | shift at 20:00 and at 01:00 | within (the window crosses midnight) |
| 8.5 | shift at 12:00 | outside |

```bash
php artisan tinker --execute='$g=app(App\Domain\Access\Services\ScopeGate::class);
$m=App\Models\AdminUser::find(<moderator_id>);
var_dump($g->reasonToRefuse($m,"moderation.mute_user",["room_category"=>5]));'
```

## 9. GFT-116 / GFT-127 — route gating and panel users

| # | Step | Expect |
|---|---|---|
| 9.1 | Moderator calls `/admins`, `/roles`, `/permissions`, `/permissions/grantable` | each `403 PERMISSION_DENIED` naming the missing key |
| 9.2 | `GET /permissions/grantable` as Admin | `can_delegate: true`, `grantable_to_roles: [manager, moderator]`, and **none** of `access.role_manage`, `settings.manage`, `economy.rates_manage` |
| 9.3 | Same as Manager | `can_delegate: false`, empty `grantable_to_roles` |
| 9.4 | Admin creates a `super_admin` or an `admin` | `403 DELEGATION_TARGET_DENIED` |
| 9.5 | Suspend an admin who is logged in | their next request `403`; all their tokens deleted |
| 9.6 | Change your own status | `400` |
| 9.7 | `GET /admins?q=&status=&role=&page=&per_page=&sort=-created_at` | filters and offset pagination per docs/03 §2.3; `sort` on an unlisted column silently falls back |
| 9.8 | `DELETE /roles/{id}` on a system role | `400` — system roles are not deletable |
| 9.9 | Delete a custom role that still has admins | `400` with `admin_count` |

## 10. Envelope and cross-cutting

| # | Check | Expect |
|---|---|---|
| 10.1 | Every response, success or failure | `success`, `message`, `data`/`error`, `meta.request_id`, `meta.timestamp` |
| 10.2 | Send `X-Request-Id: abc123` | echoed back in the response header and in `meta.request_id` |
| 10.3 | Unauthenticated call | `401 UNAUTHENTICATED` in the envelope, not Laravel's HTML page |
| 10.4 | Unknown route under `/api/` | `404 NOT_FOUND` in the envelope |
| 10.5 | 300+ requests in a minute on an admin route | `429 RATE_LIMITED` with `Retry-After` |
| 10.6 | Validation failure | `422 VALIDATION_ERROR` with per-field `details` |

---

## Known gaps in this module

Recorded so they are decisions, not surprises.

1. **`POST /admin/auth/refresh` is not implemented.** GFT-006 names "token refresh", but docs/03 §9
   lists no admin refresh endpoint (only the mobile `/auth/refresh`). Access tokens last 24 h and
   idle expiry is 60 min by default, so the panel currently re-authenticates instead of refreshing.
   Decide whether the panel needs it before the Vue work starts.
2. **`remember_device` is accepted and stored but does nothing yet.** The column and the request
   field exist; skipping the MFA challenge for a known device is not wired up.
3. **Device binding is not enforced.** docs/01 §6 specifies `DEVICE_MISMATCH` and device-bound
   tokens. That is specified for the mobile app; the admin panel does not send `X-Device-Id` and
   the check is not implemented here.
4. **`users.view_pii` is seeded but unused.** PII masking bites in module A.3 (User Management).
5. **The stock Laravel `users` table is still the framework default.** The real `users` table from
   docs/02 §2.1 lands with epic D.1; `admin_users` is deliberately separate and complete.
6. **Permission catalogue is 79 keys / 18 modules**, not the "19 modules, ~90 keys" docs/02 §2.4
   claimed. The catalogue in docs/01 §5.4 defines 78; `users.view_pii` was added from docs/01 §6.
   Both docs have been corrected to 79/18. If keys are genuinely missing, they were never written
   down — worth a review against the SoW before A.3 starts.

---

## Decisions to make before deploy (OpenAPI)

1. **`/docs` and `/api/documentation` are currently public.** The SLA (§5.1c) requires the OpenAPI
   document to be published, so this is intended locally — but in production it hands anyone the
   full admin API surface. `config/l5-swagger.php` → `routes.middleware.api` / `.docs` takes a
   middleware array; decide whether to put it behind auth, an IP allowlist, or leave it open.
2. **`L5_SWAGGER_GENERATE_ALWAYS=true`** regenerates the document on every request. Fine locally,
   wasteful in production — `.env.example` ships it as `false`.
