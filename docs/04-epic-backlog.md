# 04 — Epic Backlog

← [03 API Contract](03-api-contract.md) · next → [05 Sprint Plan](05-sprint-plan.md)

**This is the file work is picked from.** Every SLA user story becomes tickets with an owner layer, an
estimate and testable acceptance criteria. Acceptance criteria are written in Given/When/Then against
the SLA's own story lettering, so this document doubles as the UAT script
([06 §8](06-testing-qa.md#8-uat-plan)).

### How to read a ticket

`GFT-042 · BE · 6h · ← GFT-038`
→ ticket 42, **BE**ckend layer, 6 hours, blocked by GFT-038.

| Layer | Meaning |
|---|---|
| **BE** | Laravel API, jobs, migrations |
| **WEB** | Vue admin panel |
| **APP** | Flutter mobile app |
| **RT** | Realtime — Reverb channels, Redis state, Agora |
| **INF** | Infrastructure, CI/CD, third-party setup |
| **QA** | Test authoring |
| **DOC** | Documentation, OpenAPI, manuals |

### Summary

| Group | Epics | Tickets | Est. hours |
|---|---|---:|---:|
| FR.A — Super Admin & Admin | 11 | 128 | 714 |
| FR.B — Manager | 5 | 21 | 94 |
| FR.C — Moderator | 5 | 30 | 140 |
| FR.D — User / Player | 9 | 127 | 756 |
| FR.E — Common modules | 5 | 43 | 218 |
| **Total** | **35** | **349** | **1,922** |

By layer:

| Layer | Tickets | Hours |
|---|---:|---:|
| BE — backend | 203 | 1,016 |
| APP — Flutter | 59 | 401 |
| WEB — Vue admin | 62 | 369 |
| QA | 10 | 67 |
| RT — realtime | 9 | 47 |
| INF — infrastructure | 5 | 18 |
| DOC | 1 | 4 |

### The estimate against the contract

**1,922 hours = 240 developer-days.** With 2 developers that is **≈120 working days**; the contract is
**53**. Even allocating perfectly, 53 days at 8 h/day would require **≈4.5 developers working in
parallel**.

This is the most important number in the document set, so it is stated plainly rather than smoothed:

- The estimates above are **bottom-up from the SLA's own user stories**, not padded. Removing the
  Moderator app (DEV-01) is already reflected.
- The [descope levers](#descope-levers) recover ~48 hours — **2.5% of the total**. They are useful for
  absorbing a slipped milestone; they do not close this gap and it would be dishonest to imply they do.
- **Backend is the bottleneck**, not the frontends: 1,081 h (BE + RT + INF) against 770 h for both
  clients combined. Any resourcing decision should start there.

The options — more developers, a longer timeline, or a reduced V1 — are laid out with numbers in
[05 §9 R-09](05-sprint-plan.md#r-09--the-scale-gap). Raise it at the M1
review. This conversation on day 5 is planning; the same conversation on day 40 is a crisis.

---

# FR.A — Super Admin & Admin

## A.1 · Authentication & Security

**Role:** Super Admin, Admin · **Milestone:** M2

> a. Log in via email and password with MFA (OTP)
> b. Manage own profile and change password
> c. Configure session timeout and security policies
> d. Enable or disable 2FA for sub-roles

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-001 | BE | 4h | — | `admin_users`, `roles`, `permissions`, `role_permission` migrations + seeders |
| GFT-002 | BE | 6h | 001 | Admin login with Sanctum; bcrypt cost 12; lockout after 5 failures |
| GFT-003 | BE | 5h | 002 | MFA challenge — email OTP, 10-min expiry, 5 attempts, per-role toggle |
| GFT-004 | BE | 3h | 002 | `GET /admin/auth/me` returning role + effective permissions |
| GFT-005 | BE | 3h | 002 | Profile update and password change with current-password confirmation |
| GFT-006 | BE | 4h | 002 | Configurable session timeout; idle-expiry middleware; token refresh |
| GFT-007 | BE | 3h | 003 | `settings`-driven 2FA enforcement per sub-role |
| GFT-008 | WEB | 6h | 002 | Login screen, MFA step, error states, remember-device |
| GFT-009 | WEB | 4h | 004 | Auth Pinia store, axios interceptors, 401 refresh, logout-on-idle |
| GFT-010 | WEB | 4h | 005 | Profile and change-password screens |
| GFT-011 | WEB | 3h | 007 | Security settings screen — timeout, 2FA per role |
| GFT-012 | QA | 4h | 003 | Auth test suite: lockout, MFA bypass attempts, expired OTP, token replay |

**Acceptance criteria**

- **A.1a** Given a valid email and password and 2FA enabled for my role, when I submit, then I receive
  a challenge id and an emailed OTP, and no access token is issued until the OTP is verified.
- **A.1a** Given 5 consecutive failed password attempts, when I try a 6th, then I am locked out for 15
  minutes and the attempt is written to `audit_logs`.
- **A.1b** Given I am logged in, when I change my password without supplying the current one, then the
  request is rejected `422`, and when I do supply it correctly, all my other device tokens are revoked.
- **A.1c** Given a Super Admin sets the session timeout to 30 minutes, when an admin is idle for 31
  minutes, then their next request returns `401 TOKEN_EXPIRED`.
- **A.1d** Given a Super Admin disables 2FA for the Moderator role, when a Moderator logs in, then no
  MFA challenge is issued, and the change is audit-logged with actor and timestamp.

---

## A.2 · Dashboard & Analytics

**Role:** Super Admin, Admin · **Milestone:** M2 (skeleton) → M6 (full data)

> a. View real-time KPIs — active users, live rooms and DAU/MAU
> b. View revenue, recharge and gifting analytics
> c. Track engagement and retention metrics
> d. Export dashboards and reports

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-013 | BE | 6h | 001 | KPI aggregation service — active users, live rooms, DAU/MAU from Redis + MySQL |
| GFT-014 | BE | 6h | 013 | Revenue analytics — recharge, gifting, VIP, by day/week/month |
| GFT-015 | BE | 5h | 013 | Engagement metrics — session length, rooms per user, D1/D7/D30 retention |
| GFT-016 | BE | 4h | 013 | `kpi.tick` broadcast on `admin.dashboard`, throttled to 5 s |
| GFT-017 | BE | 6h | 014 | Queued export job → CSV/PDF to Spaces, signed download URL |
| GFT-018 | BE | 3h | 013 | Materialised daily-stats table + nightly rollup job (dashboards must not scan raw tables) |
| GFT-019 | WEB | 8h | 013 | Dashboard layout, KPI tiles, live-updating counters via Echo |
| GFT-020 | WEB | 8h | 014 | Revenue and gifting charts (ECharts), date-range picker, comparisons |
| GFT-021 | WEB | 5h | 015 | Engagement and retention charts, cohort table |
| GFT-022 | WEB | 4h | 017 | Export button, format picker, download centre with job status |

**Acceptance criteria**

- **A.2a** Given rooms are going live and closing, when I watch the dashboard, then live-room and
  active-user counts update within 5 seconds without a page reload.
- **A.2b** Given a date range of last month, when I open revenue analytics, then recharge, gifting and
  VIP revenue are shown separately and their sum equals the ledger total for that range.
- **A.2c** Given 30 days of activity data, when I open the retention view, then D1, D7 and D30
  retention are shown per signup cohort.
- **A.2d** Given I request a CSV export of the revenue report, then a job is queued, I am not blocked,
  and I receive a download link when it completes.
- **NFR** Given the dashboard loads, then no query scans a raw transaction table — all figures come
  from the rollup table or Redis. Verified by query log review.

---

## A.3 · User Management

**Role:** Super Admin, Admin · **Milestone:** M2

> a. Search and view user profiles, wallet balances and history
> b. Verify KYC and manage user levels and VIP status
> c. Suspend or ban users with reason logs
> d. Perform manual wallet credits and debits with an audit trail

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-023 | BE | 5h | 001 | User list — search by phone/guftagu_id/name, filters, sorting, pagination |
| GFT-024 | BE | 5h | 023 | User detail aggregate — profile, wallet, rooms, sanctions, devices, reports |
| GFT-025 | BE | 4h | 023 | PII masking in lists; `users.view_pii` unmask endpoint that audit-logs every call |
| GFT-026 | BE | 5h | 024 | KYC review — approve, reject with reason, document viewer with signed URLs |
| GFT-027 | BE | 4h | 024 | Level and VIP override endpoints, both audit-logged |
| GFT-028 | BE | 6h | 024 | Suspend / ban / unban with mandatory reason; cascades to live sessions |
| GFT-029 | BE | 8h | 024 | **Manual wallet credit/debit** — ledger row, mandatory note, `performed_by`, audit entry |
| GFT-030 | BE | 4h | 029 | Wallet freeze / unfreeze |
| GFT-031 | WEB | 8h | 023 | User list table — search, filters, bulk selection, column preferences |
| GFT-032 | WEB | 10h | 024 | User detail page — tabs for profile, wallet, activity, sanctions, reports |
| GFT-033 | WEB | 5h | 026 | KYC review screen with side-by-side document and form |
| GFT-034 | WEB | 5h | 029 | Wallet adjustment dialog — currency, amount, mandatory note, confirmation |
| GFT-035 | QA | 5h | 029 | Wallet adjustment tests — permission gate, ledger correctness, audit trail |

**Acceptance criteria**

- **A.3a** Given a user's phone number, when I search for it, then the matching user is found even
  though the column is encrypted (via `phone_hash`), and the number renders masked as `+91 98••••••21`.
- **A.3a** Given I lack `users.view_pii`, when I request the unmasked number, then I receive `403`, and
  when I do hold it, the number is returned **and** an `audit_logs` row records that I viewed it.
- **A.3b** Given a pending KYC submission, when I approve it, then the user's status becomes `verified`
  and they become eligible to withdraw.
- **A.3c** Given a live user in a room, when I ban them, then they are removed from the room within 5
  seconds, cannot rejoin, and the ban with its reason appears in their sanction history.
- **A.3d** Given I credit 1,000 coins to a user, then their balance increases by exactly 1,000, a
  `coin_transactions` row exists with `type=admin_credit`, `performed_by` set and my note attached, and
  the row's `balance_after` equals their new balance.
- **A.3d** Given I attempt a manual credit without a note, then the request is rejected `422`.

---

## A.4 · Room Management

**Role:** Super Admin, Admin · **Milestone:** M3

> a. Monitor all live rooms in real time with filters
> b. Categorise, feature and pin rooms
> c. Force-close rooms that violate policy
> d. Manage room themes, backgrounds and limits

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-036 | BE | 5h | 100 | Live-room list from Redis `rooms:live` with category/size/host filters |
| GFT-037 | BE | 4h | 036 | Room detail for admin — seats, members, recent chat, gift volume |
| GFT-038 | BE | 4h | 036 | Feature / pin / unfeature with `featured_until` |
| GFT-039 | BE | 5h | 036 | **Force-close** — mandatory reason, broadcast `room.closed`, evict all, audit + moderation log |
| GFT-040 | BE | 4h | 001 | Room categories CRUD with bilingual names and icons |
| GFT-041 | BE | 5h | 040 | Room themes CRUD — upload, premium flag, VIP gating, coin price |
| GFT-042 | BE | 3h | 040 | Global room limits in `settings` — max seats, max rooms per user, name rules |
| GFT-043 | WEB | 8h | 036 | Live-rooms grid with auto-refresh, filters and a live listener count |
| GFT-044 | WEB | 6h | 037 | Room detail drawer — seat map, member list, actions |
| GFT-045 | WEB | 5h | 040 | Categories and themes management with image upload and preview |

**Acceptance criteria**

- **A.4a** Given 50 live rooms, when I open the live-rooms view and filter by category "Music", then
  only music rooms are shown and counts refresh within 10 seconds without reload.
- **A.4b** Given a room, when I feature it until tomorrow 18:00, then it appears at the top of the
  app's explore list, and after that time it is no longer featured — without manual intervention.
- **A.4c** Given a live room with 30 listeners, when I force-close it with a reason, then all
  participants are disconnected within 5 seconds, the app shows the closure notice, `rooms.status`
  becomes `force_closed`, and both `audit_logs` and `moderation_logs` record it with my identity.
- **A.4c** Given I lack `rooms.force_close`, then the button is absent **and** a direct API call
  returns `403 PERMISSION_DENIED`.
- **A.4d** Given a theme marked premium and gated to VIP 3, when a VIP 1 user tries to apply it, then
  they receive `403 VIP_TIER_REQUIRED`.

---

## A.5 · Content Moderation & Safety

**Role:** Super Admin, Admin · **Milestone:** M6

> a. Configure banned-word and content-filter lists
> b. Review the reports queue and flagged media or text
> c. Assign, oversee and audit Moderator actions
> d. Apply warnings, temporary or permanent bans

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-046 | BE | 5h | 001 | `banned_words` CRUD — severity, scope, regex support, per-language |
| GFT-047 | BE | 6h | 046 | Content-filter service applied to room names, chat, bios, DMs, posts |
| GFT-048 | BE | 3h | 046 | CSV bulk import/export of banned words |
| GFT-049 | BE | 5h | 200 | Reports queue with priority ordering and assignment |
| GFT-050 | BE | 5h | 049 | Moderator oversight — per-moderator action counts, response times, reversal rate |
| GFT-051 | BE | 6h | 049 | Sanction engine — warning, mute, temp ban, permanent ban; scheduled expiry |
| GFT-052 | WEB | 6h | 046 | Banned-words manager with test-a-phrase tool |
| GFT-053 | WEB | 8h | 049 | Reports queue — priority lanes, filters, bulk assign |
| GFT-054 | WEB | 6h | 050 | Moderator activity dashboard |
| GFT-055 | WEB | 5h | 051 | Sanction dialog with type, duration, reason and history |

**Acceptance criteria**

- **A.5a** Given the word "xyz" is banned with severity `block` scoped to chat, when a user sends a
  chat message containing it, then the message is rejected with `422 BANNED_WORD_DETECTED` and never
  reaches other participants.
- **A.5a** Given severity `replace` with replacement `***`, then the message is delivered with the term
  replaced, and a `content_flags` row is created.
- **A.5b** Given 20 open reports of mixed priority, when I open the queue, then `critical` sorts above
  `high` above `medium` above `low`, and within a priority the oldest is first.
- **A.5c** Given a Moderator has actioned 40 reports this week, when I open the oversight view, then I
  see their action count, average response time, and any actions later reversed by an Admin.
- **A.5d** Given I apply a 24-hour temporary ban, then the user cannot log in for 24 hours, the ban
  auto-expires without manual action, and both the application and the expiry are logged.

---

## A.6 · Gift, VIP & Store Management

**Role:** Super Admin, Admin · **Milestone:** M4

> a. Create and edit gifts with pricing, animations and entrance effects
> b. Organise gift categories, tiers and limited-edition drops
> c. Configure VIP tiers, pricing and privileges
> d. Manage avatar frames, entry effects and badges

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-056 | BE | 6h | 001 | `gifts` CRUD — bilingual names, coin price, diamond value, tier, VIP gate |
| GFT-057 | BE | 5h | 056 | Animation upload to Spaces — Lottie/SVGA/MP4 validation, size cap, CDN URL |
| GFT-058 | BE | 4h | 056 | Gift categories CRUD with ordering |
| GFT-059 | BE | 5h | 056 | Limited drops — stock counter, availability window, sold-out handling |
| GFT-060 | BE | 6h | 001 | VIP tiers CRUD — pricing per duration, `privileges` JSON, badge and frame |
| GFT-061 | BE | 5h | 060 | Frames, badges and entrance effects CRUD |
| GFT-062 | BE | 3h | 056 | Gift catalogue cache with invalidation on any change |
| GFT-063 | WEB | 8h | 056 | Gift manager — grid, animation preview player, create/edit form |
| GFT-064 | WEB | 5h | 060 | VIP tier editor with a privileges matrix |
| GFT-065 | WEB | 5h | 061 | Frames, badges and effects manager with preview |

**Acceptance criteria**

- **A.6a** Given I create a gift priced at 999 coins with a 3-second SVGA animation, then it appears in
  the app catalogue within 10 minutes (cache TTL) or immediately after cache invalidation, and sending
  it deducts exactly 999 coins.
- **A.6a** Given I upload a 60 MB animation file, then the upload is rejected with a clear size error.
- **A.6b** Given a limited gift with stock 100, when 100 have been sent, then further sends return
  `409 GIFT_UNAVAILABLE` and the catalogue shows it sold out — with no oversell under concurrent sends.
- **A.6c** Given I set VIP 3 monthly price to ₹999, then the app shows ₹999 and a purchase debits
  exactly that amount.
- **A.6d** Given I gate a frame to VIP 2+, then a VIP 1 user cannot equip it and receives
  `403 VIP_TIER_REQUIRED`.

---

## A.7 · Economy, Payments & Settlements

**Role:** Super Admin, Admin · **Milestone:** M4 · ⚠ CI-01, CI-02, CI-03

> a. Configure coin and diamond conversion rates and recharge packages
> b. Review and approve payout and withdrawal requests
> c. Configure commission slabs and process settlements
> d. Reconcile payment-gateway settlements and view the transaction ledger

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-066 | BE | 5h | 001 | `conversion_rates` with effective-dating; rational arithmetic, no floats |
| GFT-067 | BE | 5h | 066 | Recharge packages CRUD — bonus coins, first-purchase-only, validity window |
| GFT-068 | BE | 6h | 066 | Commission slabs CRUD in basis points, per platform/agency/host, effective-dated |
| GFT-069 | BE | 8h | 250 | Withdrawal review — approve, reject with reason, frozen-diamond handling |
| GFT-070 | BE | 5h | 069 | Second-approval rule for withdrawals above a configurable threshold |
| GFT-071 | BE | 8h | 069 | Payout batches — build, approve, export file, mark paid, record UTR |
| GFT-072 | BE | 8h | 250 | Unified transaction ledger view with filters and CSV export |
| GFT-073 | BE | 8h | 072 | **Reconciliation engine** — gateway settlement vs `payments`, ledger vs wallets |
| GFT-074 | BE | 4h | 073 | Nightly reconciliation job with alerting on any discrepancy |
| GFT-075 | WEB | 6h | 066 | Rates and packages screens with an effective-date timeline |
| GFT-076 | WEB | 6h | 068 | Commission slab builder with overlap validation |
| GFT-077 | WEB | 8h | 069 | Withdrawal queue — review, approve, reject, batch selection |
| GFT-078 | WEB | 6h | 072 | Ledger explorer with filters, drill-through and export |
| GFT-079 | WEB | 5h | 073 | Reconciliation report with discrepancy highlighting |

**Acceptance criteria**

- **A.7a** Given I set the diamond→INR rate effective tomorrow, then today's withdrawals use today's
  rate and tomorrow's use the new one — historical requests are never re-priced.
- **A.7b** Given a pending withdrawal of 10,000 diamonds, when I approve it, then the frozen diamonds
  are converted to a paid ledger entry, and when I reject it, the exact frozen amount returns to the
  user's balance and the two paths are mutually exclusive.
- **A.7b** Given a withdrawal above the high-value threshold, when an Admin approves it, then it enters
  `pending_super_approval` and is not paid until a Super Admin also approves.
- **A.7c** Given overlapping commission slabs are submitted, then the request is rejected `422` naming
  the overlapping ranges.
- **A.7d** Given a day of transactions, when the reconciliation job runs, then for every user the sum
  of ledger movements equals their wallet balance, and any mismatch raises an alert naming the user and
  the delta.
- **NFR-10** Given 500 concurrent gift sends from one user's wallet, then the final balance is exactly
  the starting balance minus the total cost, with no lost updates. Verified by the concurrency test in
  [06 §7](06-testing-qa.md#7-financial-reconciliation-testing).

---

## A.8 · Agency & Host Management

**Role:** Super Admin, Admin · **Milestone:** M6

> a. Review and approve agencies and hosts
> b. Configure targets, commission slabs and incentives
> c. View host and agency earnings and performance
> d. Process agency settlement batches

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-080 | BE | 6h | 001 | Agencies CRUD, document upload, approve/reject with reason |
| GFT-081 | BE | 5h | 080 | Host applications queue — approve, reject, assign to agency |
| GFT-082 | BE | 6h | 081 | Host targets — create per period, auto-evaluate achievement nightly |
| GFT-083 | BE | 5h | 082 | Incentive calculation from achievement percentage |
| GFT-084 | BE | 6h | 250 | `host_earnings` nightly rollup — diamonds, splits, hours, unique gifters |
| GFT-085 | BE | 5h | 084 | Agency performance aggregate — hosts, hours, earnings, growth |
| GFT-086 | BE | 8h | 084 | Settlement generation for a period with platform/agency/host splits |
| GFT-087 | BE | 5h | 086 | Settlement approval and payout-batch linkage |
| GFT-088 | WEB | 8h | 080 | Agency list, detail, approval workflow, document viewer |
| GFT-089 | WEB | 6h | 081 | Host approval queue with intro-audio player |
| GFT-090 | WEB | 6h | 082 | Target management with progress bars |
| GFT-091 | WEB | 8h | 086 | Settlement workspace — period picker, breakdown, approve, export |

**Acceptance criteria**

- **A.8a** Given a pending agency with uploaded documents, when I approve it, then its status becomes
  `approved`, the owner is notified, and it becomes selectable by host applicants.
- **A.8b** Given a host target of 100,000 diamonds for September, when the host earns 75,000, then
  achievement shows 75% and the incentive is computed from the slab covering 75%.
- **A.8c** Given a host earned gifts across 30 days, when I open their earnings, then the daily rollup
  totals equal the sum of their `gift_transactions` diamond credits for that range, exactly.
- **A.8d** Given an approved settlement of ₹2,50,000, when I add it to a payout batch and process it,
  then the settlement status becomes `paid`, the batch total includes it once, and re-processing the
  batch does not pay twice.

---

## A.9 · Events, Games & Rankings

**Role:** Super Admin, Admin · **Milestone:** M5

> a. Create and manage events, tournaments and lucky draws
> b. Configure rewards and eligibility
> c. Define ranking rules for wealth, charm, rooms and agencies
> d. Manage ranking reward payouts

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-092 | BE | 8h | 001 | Events CRUD — types, scheduling, eligibility rules, status lifecycle |
| GFT-093 | BE | 5h | 092 | Event rewards configuration by rank range with quantity caps |
| GFT-094 | BE | 6h | 092 | Tournament scoring and leaderboard computation |
| GFT-095 | BE | 6h | 092 | Lucky draw — commit `seed_hash` before, reveal seed after, weighted selection |
| GFT-096 | BE | 6h | 001 | Ranking rules CRUD — board type, period, metric, threshold, top-N |
| GFT-097 | BE | 8h | 096 | Leaderboard engine — Redis ZSETs, period-close snapshot job |
| GFT-098 | BE | 6h | 097 | Ranking reward payout job with idempotency per snapshot |
| GFT-099 | WEB | 10h | 092 | Event builder — schedule, rules, rewards, banner, preview |
| GFT-100 | WEB | 6h | 096 | Ranking rules editor |
| GFT-101 | WEB | 5h | 098 | Reward payout review and manual trigger |

**Acceptance criteria**

- **A.9a** Given an event scheduled for tomorrow 20:00, then it appears as `upcoming` in the app, flips
  to `live` automatically at 20:00, and to `ended` at its end time — with no manual step.
- **A.9b** Given rewards for ranks 1–3 and 4–10, when the event ends with 50 participants, then exactly
  10 users are eligible and each receives the reward for their band, once.
- **A.9c** Given a wealth-daily rule with a 1,000-coin minimum, then users below it never appear on the
  board regardless of rank position.
- **A.9d** Given the daily leaderboard closes at midnight, then a snapshot is written, rewards are paid
  within 15 minutes, and re-running the payout job pays nothing further.
- **A.9a** Given a lucky draw, then `seed_hash` is visible before `draw_at` and the raw seed after, and
  the published result is reproducible from the seed.

---

## A.10 · CMS, Reports & Audit Logs

**Role:** Super Admin, Admin · **Milestone:** M6

> a. Manage banners, announcements and push campaigns
> b. Generate revenue, user, host and transaction reports
> c. Export reports as PDF or CSV
> d. Track all activities with timestamps and user attribution

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-102 | BE | 5h | 001 | Banners CRUD — placement, scheduling, action target, click tracking |
| GFT-103 | BE | 4h | 001 | Announcements CRUD — marquee, popup, targeted by role |
| GFT-104 | BE | 5h | 001 | CMS pages and FAQs with versioning and bilingual content |
| GFT-105 | BE | 8h | 260 | Broadcast campaigns — audience builder, scheduling, FCM fan-out, delivery stats |
| GFT-106 | BE | 10h | 018 | Report engine — revenue, users, hosts, transactions with a shared filter grammar |
| GFT-107 | BE | 6h | 106 | PDF and CSV renderers, queued, signed download, 7-day expiry |
| GFT-108 | BE | 6h | 001 | **Audit-log middleware** — auto-capture every admin mutation with before/after |
| GFT-109 | BE | 5h | 108 | Audit-log search API — actor, module, entity, date range |
| GFT-110 | WEB | 6h | 102 | Banner manager with placement preview |
| GFT-111 | WEB | 8h | 105 | Campaign composer — audience, preview, schedule, stats |
| GFT-112 | WEB | 8h | 106 | Report centre — builder, saved reports, download list |
| GFT-113 | WEB | 6h | 109 | Audit-log viewer with diff rendering |

**Acceptance criteria**

- **A.10a** Given a banner scheduled 01–07 September, then it is invisible before the 1st, visible
  during, and hidden after, with clicks counted per placement.
- **A.10a** Given a campaign targeted at users who recharged in the last 30 days, when I preview it,
  then the audience count is shown before sending, and after sending, sent/delivered/opened counts are
  tracked.
- **A.10b** Given a revenue report for last quarter, then its totals reconcile exactly with the ledger
  for that period.
- **A.10c** Given a 200,000-row transaction report, when I export CSV, then the job completes without
  timing out and the file contains every row.
- **A.10d** Given any admin mutation — a ban, a wallet credit, a permission grant — then `audit_logs`
  holds one row with actor, action, entity, before/after JSON, IP and timestamp. Verified by a test
  that performs one action of each kind and asserts a log row exists for every one.

---

## A.11 · Role & Permission Delegation *(added)*

**Role:** Super Admin, Admin · **Milestone:** M2
**Origin:** not an SLA epic. Added because DEV-01 removes the Moderator app, making the admin panel
serve four roles from one codebase. Elaborates A.1d and E.4a. See
[00 §4](00-overview.md#4-the-permission-model).

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-114 | BE | 5h | 001 | `admin_user_permission`, `permission_grants_log` migrations; permission seeder (~90 keys) |
| GFT-115 | BE | 6h | 114 | `PermissionResolver` — role ∪ allow − deny, tag-cached 300 s, flushed on change |
| GFT-116 | BE | 4h | 115 | `permission:` route middleware; deny by default |
| GFT-117 | BE | 8h | 115 | **`GrantPermission` action with the escalation guard** — self-grant, target and superset checks |
| GFT-118 | BE | 4h | 117 | Revoke and explicit-deny actions with cache flush |
| GFT-119 | BE | 4h | 117 | `GET /admin/permissions/grantable` — the caller's delegable subset |
| GFT-120 | BE | 4h | 117 | Scoped grants — category, agency and shift-window enforcement in the gate |
| GFT-121 | BE | 3h | 117 | Expiring grants + hourly expiry job |
| GFT-122 | BE | 3h | 117 | MFA re-entry requirement for granting `high` risk-level permissions |
| GFT-123 | BE | 4h | 115 | Channel authorisation for `admin.*` WebSocket channels via the same gate |
| GFT-124 | WEB | 8h | 119 | Permission grant UI — module accordion, checkboxes, only-grantable rendering |
| GFT-125 | WEB | 5h | 115 | Route guard, `v-permission` directive, permission-driven sidebar |
| GFT-126 | WEB | 5h | 118 | Effective-permission viewer showing origin (role / direct grant / deny) |
| GFT-127 | WEB | 4h | 114 | Admin user management — create, assign role, suspend |
| GFT-128 | QA | 8h | 117 | **Escalation test suite** — every bypass path, API-direct included |

**Acceptance criteria**

- Given an Admin who does **not** hold `payouts.approve`, when they call the grant endpoint directly
  with that key for a Moderator, then the response is `403 PERMISSION_ESCALATION_DENIED`, nothing is
  persisted, and the attempt is logged. *(The UI hiding the option does not satisfy this criterion.)*
- Given a Moderator granted only `reports.view`, when they load the panel, then the sidebar shows only
  the reports section, and a direct call to `/admin/users` returns `403 PERMISSION_DENIED`.
- Given a Moderator holding `moderation.mute_user` scoped to category 3, when they mute a user in a
  category 5 room, then the action is refused `403`.
- Given a Super Admin revokes a permission from a live Moderator session, then the Moderator's very
  next request is refused — the cache does not delay enforcement.
- Given a grant with `expires_at` in the past, then the permission is not in the effective set even
  before the expiry job has run.
- Given a Manager attempts to grant any permission, then the response is
  `403 DELEGATION_TARGET_DENIED`.
- Given any grant or revoke, then `permission_grants_log` and `audit_logs` each gain a row naming
  actor, target, permission, before/after and reason.

---
# FR.B — Manager

The Manager role is **permission-driven, not a separate build**. Every Manager screen is an admin
screen with a narrower permission set and a scope filter. These tickets cover the scoping and the
approval-request flows that are genuinely Manager-specific.

## B.1 · Operational Dashboard

**Milestone:** M6

> a. View KPIs for assigned rooms, hosts and agencies
> b. Track daily, weekly and monthly operational stats
> c. Monitor active events and campaigns

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-129 | BE | 6h | 013,120 | Scope-aware KPI service — filters every aggregate by the Manager's assigned agencies/categories |
| GFT-130 | BE | 4h | 129 | Daily/weekly/monthly operational rollups within scope |
| GFT-131 | BE | 3h | 092 | Active events and campaigns panel data |
| GFT-132 | WEB | 6h | 129 | Manager dashboard variant driven by the same components as A.2 |

**Acceptance criteria**

- **B.1a** Given a Manager scoped to agency 12, when they open the dashboard, then only agency 12's
  hosts, rooms and revenue appear — and a direct API call with another agency's id returns `403`.
- **B.1b** Given a period toggle, then daily, weekly and monthly figures are all available within scope.
- **B.1c** Given two live events, then both appear with participant counts on the Manager dashboard.

---

## B.2 · Agency & Host Operations

**Milestone:** M6

> a. Onboard and verify agencies and hosts
> b. Monitor host performance against targets
> c. Raise settlement requests for Admin approval

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-133 | BE | 5h | 080 | Manager onboarding flow — create agency/host in `pending`, cannot self-approve |
| GFT-134 | BE | 4h | 082 | Scoped host-performance view against targets |
| GFT-135 | BE | 5h | 086 | **Raise settlement request** — Manager creates `manager_raised`, Admin approves |
| GFT-136 | WEB | 6h | 133 | Manager agency/host workspace with submission states |
| GFT-137 | WEB | 4h | 135 | Settlement request form with computed preview |

**Acceptance criteria**

- **B.2a** Given a Manager submits a new agency, then it is created with status `pending` and the
  Manager cannot move it to `approved` — the approve endpoint requires `agency.approve`, which the
  Manager baseline excludes.
- **B.2b** Given hosts under the Manager's agencies, then target progress is visible for each, and
  hosts outside scope are not listed.
- **B.2c** Given a Manager raises a settlement for September, then it is created as `manager_raised`,
  appears in the Admin's approval queue, and no money moves until an Admin approves.

---

## B.3 · Content & Event Operations

**Milestone:** M5

> a. Schedule events, tournaments and lucky draws
> b. Prepare banners and campaigns for Admin approval
> c. Coordinate promotional activity across rooms

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-138 | BE | 4h | 092 | Manager-created events land in `draft`; publishing requires Admin approval |
| GFT-139 | BE | 4h | 102 | Banner and campaign submission workflow with an approval gate |
| GFT-140 | BE | 3h | 038 | Cross-room promotion — bulk feature a set of rooms within scope |
| GFT-141 | WEB | 5h | 138 | Manager event and campaign submission screens with status chips |

**Acceptance criteria**

- **B.3a** Given a Manager schedules a tournament, then it is saved as `draft` and does not appear in
  the app until an Admin approves it.
- **B.3b** Given a Manager submits a banner, then it enters the approval queue and cannot go live
  without `cms.banner_manage` approval.
- **B.3c** Given 5 rooms selected within scope, when the Manager runs a promotion, then all 5 are
  featured for the chosen window and the action is audit-logged.

---

## B.4 · User & Room Support

**Milestone:** M6

> a. Handle user queries and first-level support
> b. Monitor rooms and flag violations to Moderators
> c. Escalate unresolved issues to Admin

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-142 | BE | 6h | 001 | Support tickets — assignment, reply, resolve, SLA timers |
| GFT-143 | BE | 4h | 049 | Flag-to-Moderator action creating a `high` priority report |
| GFT-144 | BE | 3h | 142 | Escalate a ticket to Admin with a note and notification |
| GFT-145 | WEB | 8h | 142 | Support inbox — conversation view, canned replies, status |
| GFT-146 | WEB | 3h | 143 | Flag-room action from the live-rooms view |

**Acceptance criteria**

- **B.4a** Given an open ticket, when the Manager replies, then the user receives an in-app
  notification and the ticket's first-response timer stops.
- **B.4b** Given a Manager flags a live room, then a `high` priority report is created, appears in the
  Moderator queue within 5 seconds, and names the flagging Manager.
- **B.4c** Given a ticket unresolved past its SLA, when escalated, then the assigned Admin is notified
  and the escalation is recorded on the ticket.

---

## B.5 · Reports

**Milestone:** M6

> a. View and export operational reports for the assigned scope
> b. Track campaign and host performance outcomes

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-147 | BE | 4h | 106 | Scope injection into the report engine for Manager-run reports |
| GFT-148 | BE | 3h | 105 | Campaign outcome metrics — reach, opens, resulting recharges |
| GFT-149 | WEB | 4h | 147 | Manager report centre reusing the A.10 components |

**Acceptance criteria**

- **B.5a** Given a Manager exports a host report, then it contains only hosts within their scope, and
  the scope filter is applied server-side — not by hiding rows in the UI.
- **B.5b** Given a completed campaign, then reach, open rate and attributable recharge revenue are
  reported for it.

---

# FR.C — Moderator

**Delivered entirely in the admin panel (DEV-01).** Every capability below is gated by an individually
granted permission — the Moderator role baseline holds almost none of them
([02 §2.4](02-database-schema.md#24-access-control)).

## C.1 · Live Room Monitoring

**Milestone:** M6

> a. Monitor active and live rooms in real time ~~(web and mobile)~~ — **web only, DEV-01**
> b. Join rooms silently to observe conduct
> c. View room participant and seat details

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-150 | BE | 4h | 036 | Moderator live-room feed respecting category scope |
| GFT-151 | BE | 8h | 150 | **Silent join** — admin-flagged Agora subscriber token, suppressed join broadcast, mandatory audit entry |
| GFT-152 | BE | 3h | 037 | Participant and seat detail for moderation, including recent chat |
| GFT-153 | RT | 4h | 123 | `admin.moderation` subscription with permission-gated channel auth |
| GFT-154 | WEB | 8h | 150 | Moderation console — live grid, priority sort, quick filters |
| GFT-155 | WEB | 8h | 151 | Silent-observe view — audio playback, live seat map, chat stream, action bar |
| GFT-156 | WEB | 4h | 152 | Participant drawer with per-user history and prior sanctions |

**Acceptance criteria**

- **C.1a** Given rooms are live, when a Moderator opens the console, then the list updates in real time
  and shows only rooms within their granted category scope.
- **C.1b** Given a Moderator silently joins a room, then no `member.joined` event is broadcast, the
  listener count does not change, they appear in no participant list — **and** an `audit_logs` and
  `moderation_logs` row records the silent join with room, moderator and timestamp.
- **C.1b** Given a Moderator lacking `rooms.join_silent`, then the action is absent from the UI and the
  API returns `403`.
- **C.1c** Given a room with 8 seats, then the Moderator sees every occupant, their mute state and
  their speaking indicator, updating live.

---

## C.2 · In-Room Enforcement

**Milestone:** M6

> a. Mute, remove or suspend users
> b. Lock seats and force-close violating rooms
> c. Issue in-room warnings

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-157 | BE | 5h | 151 | Moderator mute — server-forced mute, broadcast, duration, log |
| GFT-158 | BE | 5h | 151 | Remove from room and remove from seat, with re-entry block |
| GFT-159 | BE | 4h | 051 | Suspend from within the console, reusing the sanction engine |
| GFT-160 | BE | 4h | 151 | Seat lock / unlock by a Moderator |
| GFT-161 | BE | 4h | 039 | Force-close from the console with a mandatory reason |
| GFT-162 | BE | 4h | 151 | In-room warning — system message plus a targeted push |
| GFT-163 | WEB | 6h | 157 | Enforcement action bar with a confirm-and-reason dialog per action |

**Acceptance criteria**

- **C.2a** Given a speaking user, when a Moderator mutes them, then their audio stops for every
  listener within 3 seconds, they cannot self-unmute for the mute duration, and the action is logged.
- **C.2a** Given a Moderator holds `mute_user` but not `kick_user`, then mute succeeds and kick returns
  `403` — permissions are independent, not tiered.
- **C.2b** Given seat 4 is locked by a Moderator, then no user can take it and the host cannot invite
  into it until it is unlocked.
- **C.2b** Given a force-close, then every participant is evicted within 5 seconds with a reason shown.
- **C.2c** Given an in-room warning, then it appears as a system message in that room's chat and as a
  push to the warned user, and it is recorded against them.

---

## C.3 · Reports & Content Review

**Milestone:** M6

> a. Review the user reports queue by priority
> b. Review flagged text, audio and media
> c. Action or escalate each report with notes

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-164 | BE | 4h | 049 | Moderator queue view — priority ordering, self-assignment, claim locking |
| GFT-165 | BE | 5h | 049 | Evidence bundle — signed media URLs, audio clips, surrounding chat context |
| GFT-166 | BE | 5h | 051 | Action-a-report — apply a sanction and resolve in one transaction |
| GFT-167 | BE | 4h | 164 | Escalate with note, target Admin and notification |
| GFT-168 | WEB | 8h | 164 | Report review screen — evidence pane, target history, action panel |
| GFT-169 | WEB | 4h | 167 | Escalation dialog and escalated-report tracking |

**Acceptance criteria**

- **C.3a** Given a mixed queue, then reports sort by priority then age, and a report claimed by one
  Moderator is not actionable by another until released.
- **C.3b** Given a report with an audio clip, then the Moderator can play it in-browser via a
  time-limited signed URL that is not publicly guessable.
- **C.3c** Given a Moderator actions a report with a 24-hour ban and a note, then the sanction applies,
  the report becomes `actioned`, the note is stored, and the reporter is notified of the outcome.
- **C.3c** Given a Moderator lacking `reports.action`, then they can read the queue but every action
  button is absent and the API refuses.

---

## C.4 · Policy Enforcement

**Milestone:** M6

> a. Enforce banned-word lists and content policy
> b. Apply temporary bans per policy
> c. Maintain moderation action logs

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-170 | BE | 4h | 047 | Flagged-content queue from the content filter |
| GFT-171 | BE | 3h | 051 | Policy-bounded temp bans — max duration enforced by permission tier |
| GFT-172 | BE | 4h | 108 | Moderation log writer on every enforcement action, append-only |
| GFT-173 | WEB | 5h | 170 | Flagged content review screen |
| GFT-174 | WEB | 4h | 172 | Own-actions log view for a Moderator |

**Acceptance criteria**

- **C.4a** Given content flagged by the filter, then it appears in the flagged queue with the matched
  rule shown.
- **C.4b** Given a Moderator granted `ban_temp` with a 72-hour policy cap, when they attempt a 30-day
  ban, then it is rejected `422` naming the cap.
- **C.4c** Given any enforcement action, then a `moderation_logs` row is written that can be read but
  never edited or deleted by anyone, including a Super Admin.

---

## C.5 · Notifications & Escalation

**Milestone:** M6

> a. Receive alerts for high-priority reports
> b. Escalate serious violations to Admin
> c. Coordinate with Managers on recurring issues

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-175 | BE | 4h | 153 | Critical-report alerting on `admin.moderation` plus optional email |
| GFT-176 | BE | 3h | 167 | Escalation notification to the target Admin |
| GFT-177 | BE | 4h | 049 | Recurring-issue detection — repeat offenders, repeat-reported rooms |
| GFT-178 | WEB | 5h | 175 | In-panel alert toasts, alert centre, unread badge |
| GFT-179 | WEB | 3h | 177 | Recurring-issues panel shared with Managers |

**Acceptance criteria**

- **C.5a** Given a `critical` report is created, then every Moderator with `moderation.live` sees an
  alert within 5 seconds without refreshing.
- **C.5b** Given an escalation, then the named Admin is notified in-panel and by email, and the report
  moves to `escalated` with both parties recorded.
- **C.5c** Given a user reported 5+ times in 24 hours, then they surface in the recurring-issues panel
  visible to Moderators and Managers.

---

# FR.D — User / Player (Mobile Application)

## D.1 · Onboarding & Account

**Milestone:** M2

> a. Register and log in via mobile OTP and social sign-in (Google/Apple)
> b. Create and edit profile — name, photo, bio, gender and date of birth
> c. Select language and light/dark theme
> d. Manage privacy and notification preferences

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-180 | BE | 5h | — | `users`, `user_profiles`, `otp_verifications`, `devices` migrations |
| GFT-181 | BE | 6h | 180 | OTP send/verify via MSG91, hashed OTP, throttling, attempt caps |
| GFT-182 | BE | 6h | 180 | Google and Apple sign-in — token verification, account link/create |
| GFT-183 | BE | 4h | 181 | Token issue, refresh rotation, device binding, logout |
| GFT-184 | BE | 5h | 180 | Profile CRUD with banned-word checks and 18+ DOB validation |
| GFT-185 | BE | 4h | 184 | Avatar upload — re-encode, resize, CDN URL |
| GFT-186 | BE | 3h | 184 | Preferences — language, theme, privacy, notification categories |
| GFT-187 | BE | 4h | 180 | Guftagu ID and Agora uid generation, collision-safe |
| GFT-188 | BE | 5h | 183 | DPDPA account deletion — 30-day grace, anonymisation job, financial retention |
| GFT-189 | APP | 6h | — | App shell — theming, routing, i18n scaffolding, secure storage |
| GFT-190 | APP | 8h | 181 | Onboarding flow — phone entry, OTP screen with auto-read, resend timer |
| GFT-191 | APP | 6h | 182 | Google and Apple sign-in buttons and native flows |
| GFT-192 | APP | 8h | 184 | Profile setup wizard — name, photo, gender, DOB |
| GFT-193 | APP | 6h | 186 | Settings — language, theme, privacy, notifications |
| GFT-194 | APP | 5h | 188 | Account deletion flow with consequence explanation and confirmation |
| GFT-195 | QA | 5h | 181 | Onboarding tests — OTP throttle, underage rejection, duplicate phone |

**Acceptance criteria**

- **D.1a** Given a valid Indian mobile number, when I request an OTP, then it arrives within 30 seconds
  and expires after 5 minutes; a 4th request within an hour is refused `429`.
- **D.1a** Given the same phone previously registered, when I verify the OTP, then I am logged into the
  existing account, not a duplicate — `is_new_user` is `false`.
- **D.1a** Given Google sign-in with an email matching an existing account, then the social account is
  linked to it rather than creating a second account.
- **D.1b** Given a date of birth under 18, when I submit the profile, then registration is refused with
  a clear message and no account is activated.
- **D.1c** Given I select Hindi, then all app strings render in Hindi immediately and the choice
  survives a restart and a re-login on another device.
- **D.1d** Given I disable gift notifications, then I receive no push for gifts while continuing to
  receive other enabled categories.
- **D.1a** Given a token issued to device A, when it is presented from device B, then the request is
  refused `401 DEVICE_MISMATCH` and the token is revoked.

---

## D.2 · Voice / Audio Rooms

**Milestone:** M3 — **the core of the product**

> a. Create, browse and join public and private (password-protected) rooms
> b. Multi-seat mic layout with host/co-host controls — invite, mute, remove and lock seats
> c. Raise-hand / request-to-speak with live speaking indicators
> d. Room categories, themes, cover images and in-room chat, emojis and reactions
> e. Room announcements, pinned messages and shareable invite links

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-196 | BE | 5h | 180 | Room domain migrations — rooms, seats, members, messages, bans, invites, handraises |
| GFT-197 | BE | 6h | 196 | Room create/edit — validation, banned words, password hashing, cover upload |
| GFT-198 | RT | 8h | 196 | **Redis room-state model** — seats hash, members ZSET, live-rooms ZSET, presence |
| GFT-199 | BE | 6h | 198 | Join / leave — sanction and ban checks, password check, capacity, membership row |
| GFT-200 | BE | 5h | 199 | `GET /rooms/{id}/state` full snapshot — the reconnect contract |
| GFT-201 | BE | 8h | 198 | Seat operations — take, leave, invite, lock, mute, kick, with atomic Redis transitions |
| GFT-202 | BE | 5h | 201 | Host and co-host authorisation rules for every seat operation |
| GFT-203 | BE | 5h | 198 | Raise-hand queue — request, withdraw, accept, reject |
| GFT-204 | RT | 6h | 198 | Reverb channel `room.{uuid}` with presence auth and the full event set |
| GFT-205 | RT | 5h | 204 | Speaking-indicator relay from Agora volume callbacks, throttled to 500 ms |
| GFT-206 | BE | 6h | 196 | In-room chat — send, history, banned-word filter, rate limit, pin, delete |
| GFT-207 | BE | 4h | 196 | Announcements and pinned messages |
| GFT-208 | BE | 4h | 196 | Invite links with token, click and join attribution |
| GFT-209 | BE | 5h | 198 | Explore, trending and search over live rooms |
| GFT-210 | BE | 5h | 198 | Async persistence job — Redis state → MySQL; reconciliation on Redis loss |
| GFT-211 | BE | 4h | 199 | Host-disconnect handling — 120 s grace, co-host promotion, auto-close |
| GFT-212 | APP | 10h | 209 | Home and explore — categories, trending, search, pull-to-refresh |
| GFT-213 | APP | 8h | 197 | Room creation flow — name, category, theme, seats, layout, privacy, cover |
| GFT-214 | APP | 16h | 200 | **Room screen** — seat grid for all layouts, avatars, frames, speaking rings, mute badges |
| GFT-215 | APP | 8h | 204 | Realtime binding — subscribe, apply events, full-state resync on reconnect |
| GFT-216 | APP | 8h | 201 | Seat interactions — take, leave, long-press host menu, invite sheet |
| GFT-217 | APP | 6h | 203 | Raise-hand UI — request, queue badge, host approval sheet |
| GFT-218 | APP | 8h | 206 | In-room chat — list, composer, emoji picker, reactions, pinned banner |
| GFT-219 | APP | 5h | 208 | Share sheet and deep-link handling for invites |
| GFT-220 | APP | 5h | 211 | Connection states — reconnecting, kicked, room-closed, host-left |
| GFT-221 | QA | 8h | 214 | Room test suite — concurrent seat takes, ban re-entry, password rooms |

**Acceptance criteria**

- **D.2a** Given a private room with a password, when I join with the wrong one, then I receive
  `423 ROOM_LOCKED` and am not added to the member list.
- **D.2a** Given I am banned from a room, when I attempt to rejoin, then I am refused `403` even with
  the correct password.
- **D.2b** Given two users tap the same empty seat simultaneously, then exactly one occupies it and the
  other receives `409 SEAT_TAKEN` — verified under a concurrency test, not by inspection.
- **D.2b** Given the host mutes seat 3, then that user's audio stops for everyone within 3 seconds and
  they cannot unmute themselves until the host releases it.
- **D.2b** Given a seat is locked, then no user can take it and no invite can place a user in it.
- **D.2c** Given I raise my hand, then the host sees the queue count increase in real time; when they
  accept, I am placed on a free seat and my Agora role is upgraded to publisher via a **new token**.
- **D.2c** Given three users are speaking, then every participant sees exactly those three highlighted,
  updating at least twice per second.
- **D.2d** Given a chat message containing a banned word, then it is rejected and never appears for any
  participant.
- **D.2e** Given a shared invite link, when a non-installed user opens it, then they reach the store,
  and after install and login they land in that room (deferred deep link).
- **Reconnect** Given my network drops for 20 seconds, then on reconnect my seat is still mine, and the
  room state I see matches the server's snapshot exactly.
- **Host loss** Given the host force-quits, then after 120 seconds a co-host is promoted, or the room
  closes and all participants are notified.

---

## D.3 · Social & Discovery

**Milestone:** M5

> a. Explore trending rooms, search rooms and users, and get recommendations
> b. Follow/followers, friends and online presence
> c. View profiles with levels, badges and VIP status
> d. Activity feed / moments — posts, likes and comments (as applicable)

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-222 | BE | 5h | 209 | Unified search over users and rooms with relevance ordering |
| GFT-223 | BE | 6h | 222 | Recommendations — follow graph, recent categories, trending fallback |
| GFT-224 | BE | 5h | 180 | Follow / unfollow, follower and following lists, counters |
| GFT-225 | BE | 5h | 224 | Friend requests — send, accept, reject, list |
| GFT-226 | BE | 4h | 198 | Presence — online, in-room, in-call, honouring privacy settings |
| GFT-227 | BE | 5h | 184 | Public profile aggregate — levels, badges, VIP, frames, rooms, visitors |
| GFT-228 | BE | 8h | 180 | **Moments** — posts, likes, comments, visibility, moderation hooks *(descope lever #1)* |
| GFT-229 | APP | 8h | 222 | Search screen with tabs, history and empty states |
| GFT-230 | APP | 6h | 223 | Recommendation rails on home |
| GFT-231 | APP | 8h | 227 | Profile screen — own and others, follow button, stats, badges |
| GFT-232 | APP | 6h | 224 | Followers/following/friends lists with follow-back |
| GFT-233 | APP | 8h | 228 | Moments feed, composer, likes, comments *(descope lever #1)* |

**Acceptance criteria**

- **D.3a** Given a partial name, then matching users and rooms return within 400 ms p95 and blocked
  users never appear.
- **D.3b** Given I follow a user, then their follower count increases immediately for both of us and
  they receive a notification if that category is enabled.
- **D.3b** Given a user has disabled online-status sharing, then their presence shows as offline to
  everyone regardless of actual state.
- **D.3c** Given I open a profile, then level, wealth and charm ranks, badges, VIP tier and frame all
  render, and wealth rank is hidden if that user has hidden it.
- **D.3d** Given I post a moment visible to followers only, then a non-follower cannot see it via the
  feed **or** by direct id.

---

## D.4 · Chat & Messaging

**Milestone:** M5

> a. One-to-one and group text chat with media sharing
> b. In-app notification centre and announcements
> c. Masked / in-app interactions where required

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-234 | BE | 6h | 180 | Conversations and participants — direct and group |
| GFT-235 | BE | 6h | 234 | Messages — send, history, media upload, reply, delete-for-me/everyone |
| GFT-236 | BE | 4h | 235 | Read receipts and unread counts |
| GFT-237 | BE | 4h | 235 | Block and privacy enforcement on DM send |
| GFT-238 | BE | 5h | 260 | In-app notification centre — list, read-all, unread count |
| GFT-239 | RT | 4h | 235 | `message.new` on `user.{uuid}` |
| GFT-240 | APP | 8h | 234 | Conversation list with unread badges and search |
| GFT-241 | APP | 10h | 235 | Chat screen — bubbles, media, reply, delete, pagination, typing indicator |
| GFT-242 | APP | 5h | 238 | Notification centre |

**Acceptance criteria**

- **D.4a** Given I send a text message, then the recipient receives it within 2 seconds while the app
  is open, and as a push when it is not.
- **D.4a** Given an image over 10 MB, then the upload is rejected client-side with a clear message.
- **D.4c** Given a user has blocked me, then my message send is refused `403 BLOCKED_BY_USER` and the
  conversation is not created — **and** the block is not disclosed to me as such.
- **D.4b** Given 5 unread notifications, then the badge shows 5 and clears on read-all.

---

## D.5 · Video & Voice Calling

**Milestone:** M3

> a. Start one-to-one voice and video calls with friends and followers
> b. Join group video calls and camera-enabled video seats within rooms (hosts and co-hosts)
> c. Camera on/off, flip front/rear camera, mute audio and optional beauty filters
> d. Network-adaptive HD video quality with automatic reconnection
> e. Call invitations with ringing, accept/decline and missed-call notifications

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-243 | BE | 5h | 180 | `calls`, `call_participants` migrations and lifecycle state machine |
| GFT-244 | BE | 6h | 243 | Initiate, accept, decline, cancel, end; eligibility from follow/friend + privacy |
| GFT-245 | BE | 4h | 243 | Group call invite and join |
| GFT-246 | BE | 5h | 243 | Missed-call detection, timeout, and push |
| GFT-247 | RT | 5h | 244 | Agora token minting for call channels; role and TTL rules |
| GFT-248 | RT | 4h | 244 | `call.*` events on `user.{uuid}` plus high-priority FCM for ringing |
| GFT-249 | BE | 4h | 201 | Camera-enabled room seats — host/co-host gating, publisher role |
| GFT-250 | APP | 10h | 247 | Agora engine wrapper — join, leave, publish, subscribe, token refresh, error map |
| GFT-251 | APP | 10h | 248 | Call UI — outgoing, incoming full-screen ringing, in-call controls, PiP |
| GFT-252 | APP | 8h | 250 | Video rendering — local and remote views, grid for group, orientation |
| GFT-253 | APP | 5h | 250 | Camera controls — on/off, flip, mute, Agora beautification *(no third-party SDK, DEV-05)* |
| GFT-254 | APP | 6h | 250 | Adaptive quality and reconnection UX |
| GFT-255 | APP | 5h | 246 | Call history with missed markers and call-back |
| GFT-256 | APP | 6h | 249 | Video seats inside rooms |

**Acceptance criteria**

- **D.5a** Given a friend is online, when I start a video call, then their device rings within 3
  seconds whether the app is foreground, background or killed.
- **D.5a** Given a user I do not follow and who restricts calls, then the call is refused `403`.
- **D.5b** Given a host enables their camera on a video seat, then all room participants see the video
  within 3 seconds and listeners cannot enable their own camera.
- **D.5c** Given I flip the camera mid-call, then the switch completes in under 1 second without
  dropping the call.
- **D.5d** Given bandwidth drops to 200 kbps, then video degrades in resolution rather than freezing or
  disconnecting, and audio continues.
- **D.5d** Given a 20-second network loss, then the call auto-reconnects and both parties see a
  reconnecting state, not a silent dead call.
- **D.5e** Given an unanswered call after 45 seconds, then it is marked `missed`, the callee gets a
  missed-call push, and it appears in both parties' history.

---

## D.6 · Virtual Gifting & Wallet

**Milestone:** M4 · ⚠ CI-01, CI-03, CI-06

> a. Send and receive animated gifts with combos and full-screen entrance effects
> b. Browse the gift catalogue by tier and category
> c. Maintain a wallet with coins (spend) and diamonds/beans (earn)
> d. Recharge coins via the payment gateway and request diamond-to-cash withdrawals
> e. View transaction and earnings history

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-257 | BE | 5h | 180 | `wallets`, `coin_transactions`, `diamond_transactions` migrations with the ledger contract |
| GFT-258 | BE | 6h | 257 | **Ledger service** — post entries with `balance_before/after`, row locking, immutability |
| GFT-259 | BE | 10h | 258 | **Gift send transaction** — debit, credit, commission split, idempotency, single DB transaction |
| GFT-260 | RT | 5h | 259 | `gift.sent` broadcast with the animation payload; combo grouping |
| GFT-261 | BE | 4h | 259 | Entrance-effect trigger rules — VIP entry, large gift, level-up |
| GFT-262 | BE | 4h | 062 | Gift catalogue API with tier/category filters and VIP gating |
| GFT-263 | BE | 6h | 257 | Razorpay order creation, idempotent, with expiry |
| GFT-264 | BE | 8h | 263 | **Razorpay webhook** — signature verify, raw store, queued processing, coin credit |
| GFT-265 | BE | 6h | 264 | GST invoice generation — sequential numbering, PDF to Spaces (⚠ CI-05) |
| GFT-266 | BE | 4h | 264 | Payment failure and refund handling |
| GFT-267 | BE | 8h | 258 | Withdrawal request — KYC gate, minimum, OTP confirm, freeze diamonds, idempotent |
| GFT-268 | BE | 5h | 258 | Transaction history APIs, cursor-paginated, per currency |
| GFT-269 | BE | 5h | 084 | Earnings summary — daily, weekly, monthly with target progress |
| GFT-270 | APP | 8h | 262 | Gift sheet — categories, tiers, prices, balance, locked-gift states |
| GFT-271 | APP | 10h | 260 | **Gift animation engine** — Lottie/SVGA player, queueing, combo escalation, full-screen |
| GFT-272 | APP | 6h | 261 | Entrance-effect rendering on room entry |
| GFT-273 | APP | 8h | 268 | Wallet screen — balances, tabs, transaction list with filters |
| GFT-274 | APP | 8h | 263 | Recharge flow — packages, Razorpay checkout, success/failure/pending states |
| GFT-275 | APP | 8h | 267 | Withdrawal flow — KYC check, amount, method, OTP, status tracking |
| GFT-276 | APP | 5h | 269 | Earnings screen with charts |
| GFT-277 | QA | 8h | 259 | **Money test suite** — concurrency, idempotency, webhook replay, ledger integrity |

**Acceptance criteria**

- **D.6a** Given I send a 999-coin gift, then my balance decreases by exactly 999, the receiver's
  diamonds increase by the configured value after commission, both ledger rows exist with correct
  `balance_before/after`, and every participant sees the animation within 2 seconds.
- **D.6a** Given the same `X-Idempotency-Key` is sent twice, then exactly one gift is sent and the
  second call returns the first result.
- **D.6a** Given I tap the same gift 10 times rapidly, then 10 separate ledger rows share one
  `combo_group` and the animation renders as a single escalating combo, not 10 overlapping animations.
- **D.6a** Given insufficient balance, then the send is refused `402 INSUFFICIENT_BALANCE`, nothing is
  deducted, and I am offered the recharge sheet.
- **D.6c** Given diamonds are earned, then they are never spendable as coins and never appear in the
  coin balance.
- **D.6d** Given a successful Razorpay payment, then coins are credited **by the webhook**, and if the
  webhook is replayed the coins are credited only once.
- **D.6d** Given the app is killed immediately after payment, then coins still arrive — client
  confirmation is not required for crediting.
- **D.6d** Given a withdrawal request, then the diamonds move to frozen immediately and cannot be spent
  while the request is pending.
- **D.6d** Given KYC is unverified, then withdrawal is refused `403 KYC_REQUIRED`.
- **D.6e** Given 6 months of transactions, then history paginates smoothly and every entry shows type,
  amount, balance after and timestamp.

---

## D.7 · VIP, Levels & Gamification

**Milestone:** M5 · ⚠ CI-02

> a. Purchase multi-tier VIP with entrance effects, badges, frames and exclusive gifts
> b. Progress through level/XP with wealth and charm levels
> c. Earn achievements, badges and daily check-in rewards
> d. Join lucky draws, mini-games, events and tournaments

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-278 | BE | 6h | 060 | VIP purchase — coins or gateway, duration, upgrade proration, auto-renew |
| GFT-279 | BE | 5h | 278 | VIP privilege enforcement across gifts, frames, themes, entry effects |
| GFT-280 | BE | 4h | 278 | Expiry job, renewal reminders, grace handling |
| GFT-281 | BE | 6h | 258 | Progression engine — account XP, wealth from coins spent, charm from diamonds earned |
| GFT-282 | BE | 5h | 281 | Level-up detection, rewards, broadcast |
| GFT-283 | BE | 5h | 281 | Achievements — criteria evaluation, progress tracking, claim |
| GFT-284 | BE | 4h | 281 | Daily check-in with streak logic and reward table |
| GFT-285 | BE | 5h | 092 | Event join, entry-cost deduction, eligibility checks, reward claim |
| GFT-286 | APP | 8h | 278 | VIP centre — tier comparison, privileges, purchase, active status |
| GFT-287 | APP | 6h | 281 | Progression screen — three tracks with next-level thresholds |
| GFT-288 | APP | 6h | 283 | Achievements and badges gallery with claim |
| GFT-289 | APP | 5h | 284 | Daily check-in with streak calendar |
| GFT-290 | APP | 8h | 285 | Events hub — list, detail, join, leaderboard, rewards, lucky-draw reveal |

**Acceptance criteria**

- **D.7a** Given I purchase VIP 3 monthly, then privileges activate immediately, my badge and frame
  appear everywhere my profile renders, and expiry is exactly 30 days later.
- **D.7a** Given I upgrade from VIP 2 to VIP 3 mid-period, then the remaining value is prorated, not
  discarded, and the calculation is shown before purchase.
- **D.7b** Given I spend 10,000 coins, then wealth points increase by exactly 10,000 and any level
  threshold crossed triggers a level-up notification once, not repeatedly.
- **D.7c** Given a 7-day check-in streak, then day 7's reward is granted, and missing a day resets the
  streak to 1 rather than continuing.
- **D.7d** Given an event with a 500-coin entry, when I join with 400 coins, then joining is refused
  and no partial deduction occurs.

---

## D.8 · Rankings & Leaderboards

**Milestone:** M5

> a. View daily, weekly and monthly leaderboards
> b. Track wealth (top gifters) and charm (top hosts) ranks
> c. View room and agency rankings

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-291 | BE | 5h | 097 | Leaderboard read API from Redis, all four boards, three periods |
| GFT-292 | BE | 4h | 291 | Own-rank endpoint including "distance to next rank" |
| GFT-293 | BE | 4h | 097 | Historical board reads from snapshots |
| GFT-294 | APP | 8h | 291 | Leaderboard screen — board tabs, period tabs, podium, own-rank sticky row |
| GFT-295 | APP | 4h | 293 | Past-period viewer |

**Acceptance criteria**

- **D.8a** Given the daily board, then it reflects gifting from the last 30 seconds and reads come from
  Redis with no database query.
- **D.8b** Given I am rank 47, then my row is pinned at the bottom of the list with the gap to rank 46
  shown.
- **D.8c** Given the monthly room board, then rooms are ranked by diamonds received in that month, and
  a room deleted mid-month is excluded rather than crashing the list.
- **Reset** Given midnight passes, then the daily board resets to empty and yesterday's is available
  under past periods.

---

## D.9 · Agency, Host & Safety

**Milestone:** M6

> a. Apply to become a host and join an agency
> b. Track host earnings and target progress
> c. Report and block users and raise content/behaviour reports
> d. Access the help centre and support

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-296 | BE | 5h | 080 | Agency browse and host application with intro audio upload |
| GFT-297 | BE | 4h | 296 | Application status, agency switching rules, leave-agency flow |
| GFT-298 | BE | 4h | 269 | Host earnings and target progress API for the app |
| GFT-299 | BE | 5h | 049 | Report submission — categories, evidence upload, rate limit, duplicate suppression |
| GFT-300 | BE | 4h | 180 | Block / unblock with full interaction cutoff |
| GFT-301 | BE | 5h | 142 | Help centre — FAQs, ticket creation, ticket thread |
| GFT-302 | APP | 8h | 296 | Become-a-host flow — agency browse, application form, audio recorder |
| GFT-303 | APP | 6h | 298 | Host dashboard — earnings, target ring, daily breakdown |
| GFT-304 | APP | 6h | 299 | Report sheet — category, description, evidence attach, confirmation |
| GFT-305 | APP | 4h | 300 | Block management screen |
| GFT-306 | APP | 6h | 301 | Help centre — FAQ browse, search, ticket list, ticket thread |

**Acceptance criteria**

- **D.9a** Given I apply to an agency, then the application appears in that agency's and the admin's
  queue, and I cannot apply to a second agency while one is pending.
- **D.9b** Given I am an approved host with a monthly target, then my dashboard shows achieved vs
  target and the figures match the admin's view of the same host exactly.
- **D.9c** Given I block a user, then they cannot DM me, call me, see my profile details or send me
  gifts, and neither of us appears in the other's follower list.
- **D.9c** Given I report the same user twice for the same incident, then the second submission is
  merged rather than creating a duplicate queue entry.
- **D.9d** Given I raise a support ticket, then I receive a ticket id and can see replies in-app.

---

# FR.E — Common Modules

## E.1 · Real-Time Voice & Video Infrastructure

**Milestone:** M3 · ⚠ CI-04, CI-08

> a. Integrate a third-party SDK (e.g., Agora) for group audio and video calling
> b. Support high-concurrency rooms and one-to-one/group calls with scalable media routing
> c. Handle active-speaker detection, noise suppression, adaptive video quality and reconnection

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-307 | INF | 4h | — | Agora project setup, App ID, certificate, environment separation |
| GFT-308 | BE | 6h | 307 | Token service — RTC and RTM, role-aware, TTL, refresh endpoint |
| GFT-309 | RT | 6h | 308 | Reverb deployment, channel auth, presence, horizontal-scale configuration |
| GFT-310 | APP | 8h | 307 | Agora SDK integration — permissions, audio session, lifecycle, error mapping |
| GFT-311 | APP | 5h | 310 | Active-speaker detection wired to the speaking-indicator relay |
| GFT-312 | APP | 4h | 310 | Noise suppression and echo cancellation configuration |
| GFT-313 | APP | 5h | 310 | Adaptive video profiles by network quality |
| GFT-314 | APP | 6h | 310 | Reconnection handling for both Agora and the WebSocket |
| GFT-315 | BE | 5h | 309 | Agora usage tracking and cost monitoring per room and per call |
| GFT-316 | QA | 8h | 309 | **Concurrency load test** — 300 rooms / 5,000 users (⚠ CI-08 to confirm targets) |

**Acceptance criteria**

- **E.1a** Given 15 users on seats in one room, then all hear each other with latency under 400 ms.
- **E.1b** Given the concurrency target from CI-08, then the load test sustains it for 30 minutes with
  p95 API latency under 800 ms and no WebSocket disconnect storm.
- **E.1c** Given background noise, then suppression measurably reduces it without clipping speech.
- **E.1c** Given a token is 5 minutes from expiry, then it refreshes automatically with no audible
  interruption.
- **Cost** Given a month of usage, then Agora minutes per room and per call are reportable — the
  platform can price its own economics.

---

## E.2 · Notification System

**Milestone:** M6 · ⚠ CI-04

> a. Push notifications (Firebase Cloud Messaging) for gifts, follows, invites, payouts and events
> b. SMS fallback for OTP and critical alerts
> c. Admin broadcast announcements to selected roles or segments
> d. In-app notification centre

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-317 | INF | 3h | — | Firebase project, APNs key, FCM server credentials per environment |
| GFT-318 | BE | 6h | 317 | Notification service — template rendering, channel routing, preference honouring |
| GFT-319 | BE | 5h | 318 | FCM dispatch with batching, retry, invalid-token pruning |
| GFT-320 | BE | 4h | 181 | MSG91 SMS service with delivery-receipt webhook |
| GFT-321 | BE | 4h | 318 | Bilingual notification templates seeded for every trigger |
| GFT-322 | BE | 6h | 318 | Segment builder for broadcasts — activity, spend, VIP, region |
| GFT-323 | BE | 4h | 318 | In-app notification storage, unread counts, read-all |
| GFT-324 | APP | 5h | 319 | FCM integration — token registration, foreground/background handling, deep links |
| GFT-325 | APP | 4h | 324 | Notification permission priming and re-request flow |

**Acceptance criteria**

- **E.2a** Given I receive a gift, then a push arrives within 10 seconds naming the sender and gift,
  and tapping it opens that room.
- **E.2a** Given I disabled gift notifications, then no gift push is sent while other categories still
  arrive.
- **E.2b** Given push delivery fails for a critical alert, then SMS fallback is used and the fallback
  is recorded.
- **E.2c** Given a broadcast to VIP users only, then only VIP users receive it and the sent count
  matches the segment size.
- **E.2d** Given pushes are delivered, then every one also appears in the in-app centre — the centre is
  the durable record, push is only the transport.

---

## E.3 · Payments & Wallet Gateway

**Milestone:** M4 · ⚠ CI-04, CI-05

> a. Integrated gateway for coin recharge (UPI, cards, net banking, wallet)
> b. Webhook handling for success, failure and refunds
> c. Auto-generated GST-compliant invoices
> d. Payout and withdrawal processing per policy

Implemented by GFT-263 through GFT-267 (D.6) and GFT-069 through GFT-074 (A.7). Additional
integration-level tickets:

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-326 | INF | 3h | — | Razorpay account, keys, webhook endpoints and secrets per environment |
| GFT-327 | BE | 5h | 326 | Payment-method configuration and per-method availability rules |
| GFT-328 | BE | 6h | 326 | RazorpayX payout integration for withdrawals and settlements |
| GFT-329 | BE | 4h | 264 | Webhook replay tool for operations, safe by idempotency |
| GFT-330 | BE | 5h | 265 | Invoice numbering series per financial year, gapless, concurrency-safe |
| GFT-331 | QA | 6h | 264 | Gateway test suite against Razorpay sandbox — success, failure, refund, replay |

**Acceptance criteria**

- **E.3a** Given the recharge screen, then UPI, cards, net banking and wallets are all offered and each
  completes end to end in sandbox.
- **E.3b** Given a webhook with an invalid signature, then it is rejected `400`, no balance changes,
  and a security event is logged.
- **E.3b** Given a webhook arriving before the client returns from checkout, then coins are credited
  correctly and the client's later confirmation is a no-op.
- **E.3c** Given a completed recharge, then a GST invoice is generated with a sequential number and is
  downloadable; two concurrent recharges never receive the same invoice number.
- **E.3d** Given an approved withdrawal batch, then the payout file or API call reflects the exact net
  amounts after commission and TDS.

---

## E.4 · Security & Privacy

**Milestone:** M2 (build) → M7 (audit)

> a. Role-based access control (RBAC) across all modules
> b. Data encrypted at rest (AES-256) and in transit (TLS 1.3)
> c. APIs secured with OAuth 2.0 / JWT and rate limiting
> d. Alignment with the IT Act 2000 and DPDPA 2023

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-332 | BE | 5h | 115 | RBAC coverage audit — assert every route declares a permission; CI check |
| GFT-333 | BE | 5h | 180 | Encrypted casts for PII columns with searchable hash companions |
| GFT-334 | INF | 4h | — | TLS 1.3, HSTS preload, cipher hardening, old-protocol disablement |
| GFT-335 | APP | 4h | 334 | Certificate pinning for the API host with a rotation plan |
| GFT-336 | BE | 5h | 183 | Rate-limiting middleware across every documented scope |
| GFT-337 | BE | 6h | 188 | DPDPA — consent capture and versioning, data export, erasure job |
| GFT-338 | BE | 5h | 299 | IT Act intermediary compliance — grievance officer, 24 h/15 day SLA tracking |
| GFT-339 | INF | 4h | — | Secrets management, key rotation runbook, `.env` provisioning |
| GFT-340 | QA | 10h | 332 | **Security test suite** — OWASP Top 10, escalation, IDOR, injection, upload abuse |
| GFT-341 | DOC | 4h | 337 | Privacy policy, T&C and guidelines integration (⚠ CI-05 supplies the text) |

**Acceptance criteria**

- **E.4a** Given the route list, then **every** admin route declares a permission — a CI check fails
  the build if one does not.
- **E.4a** Given a user token used against an admin route, then the response is `403` regardless of the
  route.
- **E.4b** Given a database dump, then phone numbers, emails, KYC documents and bank details are
  unreadable without the application key.
- **E.4c** Given the rate-limit table in [03 §16](03-api-contract.md#16-rate-limits), then each limit
  is enforced and returns `429` with `Retry-After`.
- **E.4c** Given user A's resource id, when user B requests it, then access is refused — verified by an
  IDOR sweep across every `{id}` route.
- **E.4d** Given a deletion request, then within 30 days personal data is anonymised while financial
  records remain intact and linkable to a tombstone identifier.
- **E.4d** Given a report is filed, then it is actioned within 24 hours and resolved within 15 days, or
  it is flagged as breaching the intermediary SLA.

---

## E.5 · Multilingual & Accessibility

**Milestone:** M5

> a. English and Hindi language support (extensible)
> b. Responsive, mobile-optimised admin web
> c. Accessibility best practices for the user interface

| Ticket | Layer | Est | Deps | Task |
|---|---|---:|---|---|
| GFT-342 | BE | 4h | — | `translations` table, locale resolution from `Accept-Language`, API for the panel |
| GFT-343 | BE | 4h | 342 | Bilingual content columns across gifts, categories, events, CMS, notifications |
| GFT-344 | APP | 5h | 342 | ARB localisation, language switch, RTL-safe layouts for future extension |
| GFT-345 | WEB | 5h | 342 | `vue-i18n` setup, locale switcher, translated admin strings |
| GFT-346 | WEB | 6h | 345 | Responsive admin — tablet and mobile breakpoints for every screen |
| GFT-347 | APP | 5h | 344 | Accessibility — semantic labels, contrast, touch targets, text scaling |
| GFT-348 | WEB | 5h | 346 | Accessibility — keyboard navigation, ARIA, focus order, contrast |
| GFT-349 | QA | 5h | 347 | WCAG 2.1 AA audit across both surfaces |

**Acceptance criteria**

- **E.5a** Given Hindi is selected, then every user-facing string — including gift names, event titles
  and push notifications — renders in Hindi, with a documented fallback to English for any missing key.
- **E.5a** Given a third language is added, then only translation rows and ARB files are needed — no
  code change. Demonstrated by adding one string in a dummy locale.
- **E.5b** Given a 768 px viewport, then every admin screen is usable without horizontal scrolling.
- **E.5c** Given a WCAG 2.1 AA audit, then contrast, focus order, labels and touch-target size pass on
  both surfaces, with any exception documented and justified.

---

# Descope levers

Pre-agreed, in order. Invoked only with **written client agreement**, and only against a specific
milestone slip ([05 §9](05-sprint-plan.md#9-risk-register)).

| # | Lever | Tickets | Recovers | Justification |
|---:|---|---|---:|---|
| 1 | Moments / activity feed (D.3d) | GFT-228, 233 | ~16h | SLA marks it *"(as applicable)"* |
| 2 | Beauty filters beyond Agora's built-in (D.5c) | part of GFT-253 | ~4h | SLA marks it *"optional"* |
| 3 | Tournaments — keep events and lucky draws | GFT-094, part of 290 | ~10h | Tournament format is the least-used of the three |
| 4 | Group video calls — keep 1:1 and room video seats | GFT-245, part of 252 | ~10h | Room video seats cover the core use case |
| 5 | Advanced reports — keep the four core reports | part of GFT-106 | ~8h | Core reporting satisfies A.10b |

Total recoverable: **~48 hours ≈ 3 developer-days**. Anything beyond this is a schedule
renegotiation, not a descope — say so early rather than absorbing it silently.

