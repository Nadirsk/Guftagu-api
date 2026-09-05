# 06 — Testing & QA

← [05 Sprint Plan](05-sprint-plan.md) · next → [07 DevOps & Deployment](07-devops-deployment.md)

Implements SLA §5.4: *functional, performance and load testing for high-concurrency rooms · regression
testing before each release · UAT sign-off before go-live*.

---

## 1. Strategy

| Level | Tool | Owner | Runs |
|---|---|---|---|
| Unit | Pest (PHP), Jest, Vitest | Author of the code | Every push |
| Feature / integration | Pest with a real MySQL + Redis | Author | Every push |
| API contract | Spectator against the OpenAPI spec | Dev A | Every push |
| Component | React Native Testing Library (Jest) | Dev B | Every push |
| E2E — admin | Playwright | Dev B | Nightly + pre-release |
| E2E — mobile | Detox | Dev B | Pre-release |
| Load | k6 | Dev A | M3 exit, M7 D47 |
| Security | OWASP ZAP + hand-written suite | Dev A | M7 D48 |
| Accessibility | axe-core + manual | Dev B | M7 D48 |
| UAT | Manual, scripted from [04](04-epic-backlog.md) | Client + PM | M7 D49–D50 |

**Coverage targets** — floors, not goals:

| Area | Minimum |
|---|---|
| Economy domain (ledger, gifting, recharge, withdrawal) | **95%** |
| Access domain (permissions, delegation, guard) | **95%** |
| Room and realtime domain | 80% |
| Everything else backend | 70% |
| React Native — hooks, stores and repositories | 60% |
| Vue — stores and permission guards | 60% |

The two 95% figures are non-negotiable. Money and privilege are the only two places where a bug is
unrecoverable.

---

## 2. What gets tested where

Do not test the same thing three times, and do not leave a layer untested because "the other layer
covers it".

| Concern | Tested at |
|---|---|
| Validation rules | Feature test on the endpoint |
| Business invariants (balance never negative) | Unit test on the service + feature test on the endpoint |
| Permission enforcement | Feature test per route + the dedicated escalation suite |
| Race conditions | Concurrency test with parallel processes, never with mocks |
| Realtime event shape | Feature test asserting the broadcast payload |
| UI state from events | Widget / component test with a faked socket |
| Third-party integration | Sandbox integration test, plus a contract test with recorded fixtures |
| End-to-end journeys | E2E, sparingly — expensive and brittle; reserve for the five critical paths |

### The five critical paths (E2E)

1. Register by OTP → complete profile → join a room → take a seat → speak
2. Recharge coins → send a gift → receiver's diamonds increase → wallet history reflects both
3. Host applies → agency approves → earns → requests withdrawal → admin approves → paid
4. User reports → Moderator actions → sanction applies → user is blocked from rejoining
5. Super Admin grants a scoped permission → Moderator's panel changes → escalation attempt refused

---

## 3. Functional testing

Every acceptance criterion in [04](04-epic-backlog.md) becomes at least one automated test. The
Given/When/Then phrasing maps directly:

```php
// A.3d — manual wallet credit
it('credits a wallet and writes a complete audit trail', function () {
    $admin = adminWith('wallet.manual_credit');
    $user  = User::factory()->withWallet(coins: 500)->create();

    actingAs($admin, 'admin')
        ->postJson("/api/v1/admin/users/{$user->id}/wallet/credit", [
            'currency' => 'coins', 'amount' => 1000, 'note' => 'Goodwill — ticket #482',
        ])->assertOk();

    expect($user->wallet->fresh()->coin_balance)->toBe(1500);

    $tx = CoinTransaction::latest('id')->first();
    expect($tx->type)->toBe('admin_credit')
        ->and($tx->balance_before)->toBe(500)
        ->and($tx->balance_after)->toBe(1500)
        ->and($tx->performed_by)->toBe($admin->id)
        ->and($tx->note)->toBe('Goodwill — ticket #482');

    expect(AuditLog::where('action', 'wallet.credit')
        ->where('admin_user_id', $admin->id)->exists())->toBeTrue();
});

it('refuses a manual credit without a note', function () {
    actingAs(adminWith('wallet.manual_credit'), 'admin')
        ->postJson("/api/v1/admin/users/1/wallet/credit", ['currency' => 'coins', 'amount' => 1000])
        ->assertStatus(422);
});
```

---

## 4. Concurrency testing

Non-deterministic bugs do not appear in sequential tests. These four run with genuinely parallel
processes against a real MySQL — not in a transaction, not with mocked locks.

| Test | Scenario | Assertion |
|---|---|---|
| **Seat race** | 20 processes take the same empty seat simultaneously | Exactly 1 succeeds; 19 receive `409 SEAT_TAKEN`; `room_seats` holds one occupant |
| **Wallet race** | 500 gift sends from one wallet with a balance for exactly 300 | Exactly 300 succeed; final balance is 0; ledger sums to 0; no negative balance at any point |
| **Idempotency race** | The same `X-Idempotency-Key` sent 10 times in parallel | One gift; nine return the identical original response; one ledger row |
| **Limited-drop race** | 200 users buy a gift with stock 100 | Exactly 100 succeed; `stock` reaches 0, never below |
| **Invoice race** | 50 concurrent recharges complete | 50 invoices, 50 distinct sequential numbers, no gap and no duplicate |

```php
it('never oversells a limited gift under concurrency', function () {
    $gift = Gift::factory()->limited(stock: 100)->create();
    $users = User::factory()->count(200)->withWallet(coins: 100_000)->create();

    $results = parallelMap($users, fn ($u) => sendGiftAs($u, $gift));

    expect(collect($results)->where('status', 200)->count())->toBe(100);
    expect($gift->fresh()->stock)->toBe(0);
    expect(GiftTransaction::where('gift_id', $gift->id)->count())->toBe(100);
});
```

---

## 5. Load testing

**SLA §5.4a explicitly requires load testing for high-concurrency rooms.** Targets are ⚠ CI-08; the
provisional figures below are NFR-04 until the client confirms.

### Scenarios (k6)

| # | Scenario | Profile | Pass criteria |
|---|---|---|---|
| L1 | Room join storm | 1,000 users join 50 rooms over 60 s | p95 join < 2 s; no 5xx; all seat states consistent afterwards |
| L2 | Sustained rooms | 300 rooms × 15 seats + 5,000 listeners, 30 min | p95 API < 800 ms; WebSocket disconnect rate < 1%; memory flat |
| L3 | Gift storm | 500 gifts/second into one room for 5 min | No ledger drift; broadcast lag < 2 s; MySQL CPU < 70% |
| L4 | Chat storm | 100 messages/second in one room | Delivery < 1 s; no message loss; rate limits hold |
| L5 | Explore load | 10,000 explore requests/minute | p95 < 400 ms; all served from Redis, zero table scans |
| L6 | Recharge burst | 200 concurrent orders + webhooks | All credited exactly once; invoice numbers unique |

### What to watch

Beyond pass/fail: MySQL slow-query log, Redis memory and eviction count, Reverb connection count and
message rate, Horizon queue depth (should never exceed 1,000 pending), Agora concurrent channels, and
p50/p95/p99 latency per endpoint. A pass with a queue depth of 40,000 is not a pass.

### Where it runs

Staging, sized proportionally to production, with production-shaped seed data (not 50 rows). Run at
**M3 exit** (early signal) and again at **M7 D47** (acceptance). Results are a handover artefact
([08 §2](08-handover-amc.md#2-handover-kit)).

---

## 6. Security testing

Maps to E.4 and SLA §5.3. Run at M7 D48, and the escalation suite on every push from M2.

### 6.1 OWASP Top 10 checklist

| # | Category | Test |
|---|---|---|
| A01 | Broken access control | Every admin route called without its permission → `403`. Every `{id}` route called with another user's id → `403`/`404`. Automated sweep over the route list. |
| A02 | Cryptographic failures | Database dump inspected: phone, email, KYC, bank fields unreadable. TLS scan shows 1.3 only. |
| A03 | Injection | SQLi payloads in every search, filter and sort parameter. XSS payloads in every free-text field, checked at render in both clients. |
| A04 | Insecure design | Rate limits verified per [03 §16](03-api-contract.md#16-rate-limits). Wallet locking verified by the concurrency suite. |
| A05 | Misconfiguration | `APP_DEBUG=false` in production, no directory listing, security headers present, default credentials absent. |
| A06 | Vulnerable components | `composer audit` and `npm audit` (admin **and** mobile) in CI; build fails on a high-severity advisory. |
| A07 | Auth failures | Lockout after 5 failures. Token reuse after logout → `401`. Device-mismatch revocation. OTP brute force capped. |
| A08 | Integrity failures | Webhook signature verification tested with a tampered payload. |
| A09 | Logging failures | Every security event — failed login, escalation attempt, PII view, signature failure — produces a log entry. |
| A10 | SSRF | No endpoint fetches a user-supplied URL. Verified by grep and code review, documented as an architectural constraint. |

### 6.2 The escalation suite (GFT-128)

The permission model's correctness is a security property, not a feature. This suite runs on **every
push** from M2 onward.

| Test | Expected |
|---|---|
| Admin without `payouts.approve` grants it to a Moderator | `403 PERMISSION_ESCALATION_DENIED`, nothing persisted |
| Admin grants a permission it holds | `200`, target's effective set grows by exactly that key |
| Admin grants a permission to itself | `403 SELF_GRANT_DENIED` |
| Manager attempts any grant | `403 DELEGATION_TARGET_DENIED` |
| Moderator attempts any grant | `403 DELEGATION_TARGET_DENIED` |
| Grant request bypassing the UI, straight to the API | Same refusals — the UI is not the control |
| Grant with a forged `role_id` in the payload | Ignored; role comes from the authenticated record |
| Revoked permission used on the next request | `403` — cache does not delay enforcement |
| Expired grant | Not in the effective set, before the expiry job runs |
| Scoped permission used outside its scope | `403` |
| `high` risk permission granted without MFA re-entry | `403 MFA_REQUIRED` |
| Moderator subscribes to `admin.moderation` without `moderation.live` | Channel auth refused |
| Every admin route lacking a declared permission | **CI check fails the build** (GFT-332) |

### 6.3 Manual penetration checklist

Session fixation · JWT `alg` tampering · IDOR across all resource types · file-upload abuse (polyglot,
oversized, wrong magic bytes, path traversal in filename) · mass assignment on every PATCH · CSRF on
the admin panel · unauthenticated WebSocket subscription attempts · Razorpay webhook forgery ·
enumeration via OTP and login timing differences.

---

## 7. Financial reconciliation testing

The most important tests in the project. Failure here is not a defect, it is a loss of trust.

### 7.1 Ledger integrity

Runs nightly in production (GFT-074) **and** as a CI test against seeded data.

```php
it('keeps every ledger chain intact', function () {
    seedRealisticEconomy(users: 100, days: 30);

    User::each(function ($user) {
        $rows = CoinTransaction::where('user_id', $user->id)->orderBy('id')->get();
        $running = 0;
        foreach ($rows as $row) {
            expect($row->balance_before)->toBe($running);
            $running += $row->direction === 'credit' ? $row->amount : -$row->amount;
            expect($row->balance_after)->toBe($running)
                ->and($running)->toBeGreaterThanOrEqual(0);
        }
        expect($user->wallet->coin_balance)->toBe($running);
    });
});
```

### 7.2 The reconciliation matrix

| Check | Rule |
|---|---|
| Wallet vs ledger | For every user, `wallets.coin_balance` = Σ(credits) − Σ(debits) in `coin_transactions`. Same for diamonds. |
| Gift conservation | For every gift: `coin_cost` debited from sender = `diamond_credit` to receiver + platform commission + agency commission, at the configured rate. No coins vanish, none appear. |
| Recharge vs gateway | Σ(`payments.amount_paise` captured) = gateway settlement report for the day. |
| Recharge vs coins | Every `paid` order has exactly one `recharge` ledger credit of the right amount. |
| Withdrawal conservation | frozen + paid + reverted = requested, for every withdrawal. |
| Invoice completeness | Every `paid` order has exactly one invoice; numbering is sequential and gapless per financial year. |
| Idempotency | No `idempotency_key` appears twice in any ledger table. |
| Immutability | No ledger row's `created_at` differs from its insert time; no `UPDATE` recorded against these tables in the binlog. |

### 7.3 Webhook resilience

| Test | Expected |
|---|---|
| Same webhook delivered 5 times | Coins credited once |
| Webhook arrives before the client returns | Coins credited; client confirmation is a no-op |
| Webhook arrives while the app is killed | Coins credited |
| Invalid signature | `400`, no state change, security log written |
| Webhook for an unknown order | `200` (acknowledged), logged as `ignored`, no crash |
| Webhook processing throws | Row stays `failed`, retried by the queue, replayable via GFT-329 |
| Out-of-order events (`captured` after `failed`) | Final state is correct; state machine rejects invalid transitions |

---

## 8. UAT plan

**M7 D49–D50.** SLA §5.4c: UAT sign-off before go-live.

### 8.1 How the script is built

The UAT script is generated from [04](04-epic-backlog.md) — every acceptance criterion becomes a
numbered UAT case with its Epic ID. No separate authoring, no drift between what was specified and
what is signed off.

### 8.2 Participants and environment

Client Super Admin, one Admin, one Manager, one Moderator, and 3–5 real users on real devices (mix of
Android and iOS, at least one low-end Android). Run on **staging with production-like data** — never
on production, never on empty seed data.

### 8.3 Structure

| Block | Cases | Who |
|---|---|---|
| Admin — auth, permissions, delegation | A.1, A.11 | Super Admin |
| Admin — users, rooms, moderation | A.3, A.4, A.5, C.1–C.5 | Admin, Moderator |
| Admin — economy, gifts, VIP | A.6, A.7 | Admin |
| Admin — agency, events, CMS, reports | A.8, A.9, A.10 | Admin, Manager |
| App — onboarding and profile | D.1 | Users |
| App — rooms and calling | D.2, D.5 | Users, multi-device |
| App — gifting and wallet | D.6 | Users |
| App — social, VIP, rankings, events | D.3, D.4, D.7, D.8 | Users |
| App — host and safety | D.9 | Users |
| Cross-cutting | E.1–E.5 | All |

### 8.4 Defect classification and SLA

Matches the escalation matrix in SLA §8.3.

| Severity | Definition | Response | Blocks go-live |
|---|---|---|---|
| **S1 Critical** | Money incorrect, data loss, security breach, app unusable | 4 h (L1) | **Yes** |
| **S2 High** | A core feature broken with no workaround | 8 h (L2) | **Yes** |
| **S3 Medium** | Feature impaired, workaround exists | 24 h (L3) | No — fixed in warranty |
| **S4 Low** | Cosmetic, copy, minor UX | Next release | No |

### 8.5 Exit criteria

- Zero open S1 and zero open S2
- All S3 logged with an owner and a target date inside the warranty period
- Every critical-path case (the five in [§2](#the-five-critical-paths-e2e)) passed
- Load test L2 passed at the CI-08 target
- Security suite passed with no high-severity finding open
- Reconciliation clean for the full UAT period
- **Signed UAT document** — template in [08 §2](08-handover-amc.md#2-handover-kit)

---

## 9. Regression

SLA §5.4b: regression testing before each release.

| When | What |
|---|---|
| Every push | Unit + feature + contract + escalation suite (~6 min) |
| Nightly on `develop` | Full backend suite + Playwright admin E2E + React Native component tests |
| Before each milestone demo | Nightly suite + manual smoke of that milestone's features |
| Before production release | Everything, plus the five critical paths on real devices, plus load L1 and L3 |

**The pre-release regression checklist** — 30 minutes, manual, every single release, no exceptions:

1. Register a brand-new account by OTP
2. Create a room, join from a second device, hear audio both ways
3. Take a seat, mute, unmute, raise hand, accept
4. Recharge ₹100 in sandbox, confirm coins land
5. Send a gift, confirm animation, confirm both balances
6. Request a withdrawal, approve it in the panel
7. Report a user, action it as a Moderator, confirm the sanction
8. Grant a permission, confirm the Moderator's panel changes
9. Force-close a room, confirm eviction
10. Confirm the reconciliation report is clean

---

## 10. Device and browser matrix

### Mobile

| Platform | Devices | Why |
|---|---|---|
| Android | Low-end (2 GB RAM, Android 10), mid (Android 12), flagship (Android 14) | Gift animations and video are the memory pressure; the low-end device is the real test |
| iOS | iPhone SE (small screen), iPhone 13, iPhone 15 Pro | Layout at 375 pt; iOS audio-session behaviour |
| Network | 4G, 3G-throttled, lossy Wi-Fi, airplane-mode toggle | Reconnection paths are where realtime apps fail |

Minimum supported: **Android 8.0 (API 26)** and **iOS 13**.

### Admin panel

Chrome, Edge, Firefox, Safari — current and current−1. Viewports 1920, 1440, 1024, 768. Screen readers
NVDA and VoiceOver for the accessibility pass.

---

## 11. Test data

- **Factories, not fixtures** — every domain has a Laravel factory with realistic states.
- `seedRealisticEconomy()` produces 30 days of recharges, gifts, withdrawals and rankings across 100
  users; load and reconciliation tests run against it.
- **No production data in any lower environment.** If production data is ever needed for debugging, it
  is anonymised first — phone, email, KYC and bank fields replaced (DPDPA, [01 §6.1](01-architecture.md#61-indian-regulatory-alignment)).
- Sandbox credentials only in staging: Razorpay test keys, Agora staging project, MSG91 test route,
  Firebase dev project.

---

## 12. CI gates

A pull request cannot merge unless every one of these passes. Details in
[07 §3](07-devops-deployment.md#3-ci-pipeline).

| Gate | Fails the build when |
|---|---|
| Pint / PSR-12 | Any style violation |
| ESLint / Prettier | Any lint error |
| `tsc --noEmit` (mobile + admin) | Any type error |
| Backend test suite | Any failure |
| **Escalation suite** | Any failure |
| **Ledger integrity test** | Any failure |
| Route-permission check (GFT-332) | Any admin route without a declared permission |
| OpenAPI drift | Generated spec differs from the committed spec |
| Coverage floors | Economy or Access below 95% |
| `composer audit` / `npm audit` | Any high-severity advisory |
