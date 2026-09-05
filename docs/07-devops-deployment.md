# 07 — DevOps & Deployment

← [06 Testing & QA](06-testing-qa.md) · next → [08 Handover & AMC](08-handover-amc.md)

Implements SLA §5.1 (*Git with branch protection and mandatory code reviews · ESLint, Prettier and
PSR-12 enforced via CI*) and §10 M7 (*production deployment*).

---

## 1. Environments

| | Local | Staging | Production |
|---|---|---|---|
| **Host** | XAMPP on Windows | 1× DO droplet 2 vCPU / 4 GB | DO LB → 2× droplet 4 vCPU / 8 GB |
| **PHP** | 8.3 | 8.3 | 8.3 |
| **MySQL** | local 8.0 | Managed dev, 1 GB | Managed 2 vCPU / 4 GB, daily backup + PITR |
| **Redis** | WSL2 / Memurai | Managed 250 MB | Managed 1 GB, eviction `noeviction` |
| **Storage** | local disk | Vultr Object Storage `guftagu-staging` | Vultr Object Storage `guftagu-prod` + CDN |
| **WebSocket** | `reverb:start` | Reverb on the droplet | Reverb on both droplets, sticky sessions at the LB |
| **Queues** | `queue:work` | Horizon, 2 workers | Horizon, 6 workers across 3 queues |
| **Agora** | staging project | staging project | production project |
| **Razorpay** | test keys | test keys | live keys |
| **MSG91** | test route | test route | transactional route |
| **Domain** | `localhost` | `staging-api.guftagu.com` · `staging-admin.guftagu.com` | `api.guftagu.com` · `admin.guftagu.com` |
| **Debug** | on | on | **off**, always |

Queue separation in production: `high` (notifications, realtime fan-out), `default` (business jobs),
`low` (exports, rollups, archival). A 200,000-row export must never delay a gift notification.

---

## 2. Repository & branching

**One private GitHub monorepo.** SLA §7.5 says "GitHub (private repositories)"; one repo with three
apps satisfies it and keeps the API contract, the schema and the clients versioned together.

```
Guftagoo/
├── ROADMAP.md
├── docs/
├── backend/
├── admin-web/
├── mobile/
└── .github/workflows/
```

### Branches

| Branch | Purpose | Protection |
|---|---|---|
| `main` | Production. Tagged releases only. | Protected — PR only, 1 approval, all checks green, no force push, no direct commit including admins |
| `develop` | Integration. Auto-deploys to staging. | Protected — PR only, 1 approval, all checks green |
| `feature/GFT-###-short-slug` | One ticket | — |
| `bugfix/GFT-###-slug` | Defect during development | — |
| `hotfix/short-slug` | Production emergency; branches from `main`, merges to both | 1 approval, expedited |
| `release/vX.Y.Z` | Release stabilisation when needed | — |

### Commits

Conventional Commits with the ticket id:

```
feat(economy): add gift send transaction with idempotency [GFT-259]
fix(rooms): prevent double seat occupancy under concurrency [GFT-201]
test(access): cover every permission escalation path [GFT-128]
docs(api): document withdrawal endpoints [GFT-267]
```

Types: `feat` `fix` `refactor` `test` `docs` `chore` `perf` `ci`.

### Pull requests

Template requires: ticket id · what changed · how it was tested · screenshots for UI · migration notes
· breaking-API notes. **Mandatory review by the other developer** (SLA §5.1b) — self-merge is disabled
at the branch-protection level, not by convention.

---

## 3. CI pipeline

`.github/workflows/` — three workflows, path-filtered so a mobile change does not run the PHP suite.

### `backend.yml`

```yaml
on:
  pull_request: { paths: ['backend/**'] }
  push: { branches: [develop, main], paths: ['backend/**'] }

jobs:
  test:
    services:
      mysql: { image: mysql:8.0 }
      redis: { image: redis:7 }
    steps:
      - composer install --no-interaction --prefer-dist
      - ./vendor/bin/pint --test                    # PSR-12 (SLA §5.1a)
      - ./vendor/bin/phpstan analyse --level=6
      - php artisan migrate --force
      - ./vendor/bin/pest --coverage --min=70
      - ./vendor/bin/pest --group=escalation        # must always pass
      - ./vendor/bin/pest --group=ledger            # must always pass
      - php artisan route:check-permissions         # GFT-332
      - php artisan scramble:export && git diff --exit-code openapi.json
      - composer audit
```

### `admin-web.yml`

`npm ci` → `eslint .` → `prettier --check .` → `vue-tsc --noEmit` → `vitest run` → `npm run build` →
`npm audit --audit-level=high`.

### `mobile.yml`

`npm ci` → `eslint .` → `prettier --check .` → `tsc --noEmit` → `jest --coverage` →
`npm audit --audit-level=high` → `./gradlew assembleDebug` (PR) / `bundleRelease` (tag).

iOS builds run on a `macos-latest` runner and **only on a tag**: a macOS minute costs about ten
Linux minutes, and nothing about reviewing a pull request needs an `.ipa`. Android covers the
"does it still compile against native modules" question on every PR, which is the one that
actually catches breakage.

### Branch-protection required checks

`backend/test` · `admin-web/build` · `mobile/analyze` · `escalation-suite` · `ledger-integrity` ·
`openapi-drift`. All six green, or the merge button stays disabled.

---

## 4. Infrastructure

### 4.1 Provisioning (M7 D46)

1. **VPC** `guftagu-prod` in `blr1` (Bangalore — lowest latency for the target market).
2. **Droplets** — 2× Ubuntu 24.04, 4 vCPU / 8 GB, private networking only.
3. **Managed MySQL 8** — 2 vCPU / 4 GB, daily backups, 7-day PITR, private-network access only.
4. **Managed Redis 7** — 1 GB, `noeviction` (evicting room state silently is worse than failing loudly).
5. **Vultr Object Storage** `guftagu-prod` + CDN, CORS restricted to the app and admin origins.
6. **Load balancer** — HTTPS, TLS 1.3, **sticky sessions** (Reverb requires them), health check on
   `/api/v1/health`.
7. **Firewall** — 80/443 from the LB only; 22 from the office IP only; MySQL and Redis private only.
8. **DNS** — `api`, `admin`, `ws`, `cdn` records; Let's Encrypt with auto-renew.
9. **Monitoring** — DO alerts on CPU > 80%, memory > 85%, disk > 80%, droplet down.

### 4.2 Server stack

nginx · PHP 8.3-FPM (`bcmath`, `redis`, `gd`, `intl`, `zip`, `opcache`) · Supervisor for Horizon,
Reverb and the scheduler · Certbot · fail2ban on SSH.

`opcache.validate_timestamps=0` in production, with a reset on deploy.

### 4.3 Supervisor programs

| Program | Command | Instances |
|---|---|---|
| `guftagu-horizon` | `php artisan horizon` | 1 per droplet |
| `guftagu-reverb` | `php artisan reverb:start --host=0.0.0.0 --port=8080` | 1 per droplet |
| `guftagu-scheduler` | `php artisan schedule:work` | **1 total** — leader-elected, never both droplets |

The scheduler running twice is how leaderboard rewards get paid twice. Pin it to one node with a Redis
lock (`withoutOverlapping` plus a node guard).

### 4.4 Scheduled jobs

| Job | Cadence | Purpose |
|---|---|---|
| `rooms:reconcile` | every 5 min | Redis ↔ MySQL room state, orphaned rooms |
| `presence:sweep` | every minute | Vacate seats whose heartbeat lapsed |
| `leaderboard:snapshot` | daily 00:00, weekly Mon, monthly 1st | Snapshot ZSETs, pay rewards |
| `vip:expire` | hourly | Expire subscriptions, send renewal reminders |
| `sanctions:expire` | every 15 min | Lift expired bans and mutes |
| `permissions:expire` | hourly | Remove expired grants |
| `earnings:rollup` | daily 01:00 | `host_earnings` daily aggregation |
| `stats:rollup` | daily 01:30 | Dashboard rollup table |
| `targets:evaluate` | daily 02:00 | Host target achievement |
| **`reconcile:financial`** | daily 03:00 | Ledger integrity + gateway reconciliation, **alerts on any drift** |
| `archive:partitions` | monthly | Export and drop old message partitions |
| `dpdpa:erase` | daily 04:00 | Process due deletion requests |
| `exports:cleanup` | daily | Remove expired report files |

---

## 5. Deployment

### 5.1 Flow

```
feature/GFT-### → PR → review + CI → develop → auto-deploy to staging
                                        ↓
                          release/vX.Y.Z → PR → main → tag → manual deploy to production
```

Staging deploys automatically on every merge to `develop`. **Production is always manual** — a human
decides when money-handling software changes.

### 5.2 Zero-downtime release

Atomic-symlink deployment (Deployer or a scripted equivalent):

```
/var/www/guftagu/
├── releases/20260831140000/
├── shared/  (.env, storage/)
└── current -> releases/20260831140000
```

Steps: clone the tag → `composer install --no-dev -o` → link `shared` → `migrate --force` →
`config:cache route:cache view:cache event:cache` → build and sync the admin panel to
`admin-web/dist` → **atomically repoint `current`** → `php-fpm reload` → `horizon:terminate` (workers
restart on the new code) → `reverb:restart` → `opcache reset` → smoke test → keep the last 5 releases.

### 5.3 Migration discipline

Money and rooms are live. Migrations must be safe to run while traffic flows.

- **Expand, then contract.** Add a column, deploy code that writes both, backfill, deploy code that
  reads the new one, drop the old one — across three releases, never one.
- **Never** rename or drop a column in the same release that changes its readers.
- Large backfills run as queued jobs in batches, never inside a migration.
- `ALTER` on a large table uses an online strategy; anything that would lock `coin_transactions` or
  `room_messages` goes in a maintenance window.
- Every migration has a tested `down()`. A migration you cannot reverse is a deployment you cannot roll
  back.

### 5.4 Rollback

| Situation | Action |
|---|---|
| Bad code, no migration | Repoint `current` to the previous release, reload FPM. Under 60 seconds. |
| Bad code with a reversible migration | `migrate:rollback --step=1`, then repoint. |
| Bad code with an irreversible migration | Roll code back, hotfix forward. This is why expand-then-contract is mandatory. |
| Data corruption | Restore MySQL PITR to just before the incident. **Financial data — never overwrite, restore to a parallel database and reconcile by hand.** |

### 5.5 Release checklist

Before tagging: all CI green · staging soaked ≥ 24 h · [06 §9](06-testing-qa.md#9-regression)
regression checklist done · migrations reviewed for lock risk · rollback plan written · release notes
drafted · client informed if there is user-visible change · **not on a Friday afternoon**.

---

## 6. Secrets

- Never in the repo. `.env.example` is committed with every key and no value; a CI check fails the
  build if a key exists in `.env.example` but not in the deploy secret store.
- Production secrets live in GitHub Actions secrets and in `/var/www/guftagu/shared/.env` (mode `600`,
  owner `www-data`).
- Rotation: Sanctum key, Agora certificate, Razorpay keys and the AES data key each have a documented
  rotation procedure in the runbook. The AES key requires a re-encryption job — written and tested
  before go-live, not after the first incident.
- Client credentials are handed over at M7 D53 through a password manager or an encrypted channel,
  **never** by email or chat ([08 §2](08-handover-amc.md#2-handover-kit)).

---

## 7. Monitoring & alerting

| Layer | Tool | Alerts on |
|---|---|---|
| Errors | Sentry (backend, Vue, React Native) | New issue, error-rate spike |
| Uptime | UptimeRobot / DO checks | `api`, `admin`, `ws` down for 2 min |
| Infrastructure | DO monitoring | CPU > 80%, RAM > 85%, disk > 80% |
| Queues | Horizon | Queue depth > 1,000; any failed job |
| Database | DO Insights + slow-query log | Slow queries > 1 s; connections > 80% |
| Redis | DO metrics | Memory > 80%; any eviction (should be zero) |
| Realtime | Custom metric | Reverb connection count; disconnect-rate spike |
| **Financial** | `reconcile:financial` | **Any ledger drift — pages immediately** |
| Business | Dashboard | Recharge success rate < 90%; gift failure rate > 1% |

### Alert routing

| Severity | Channel | Response |
|---|---|---|
| **P1** — down, money incorrect, security | Phone + email | 4 h (SLA L1) |
| **P2** — core feature broken | Email + chat | 8 h (SLA L2) |
| **P3** — degraded | Email | 24 h (SLA L3) |

Health endpoint `GET /api/v1/health` returns MySQL, Redis, queue-depth and Reverb status with an
overall verdict — used by the LB and by uptime monitoring.

---

## 8. Mobile release

### 8.1 Build configuration

| | Development | Staging | Production |
|---|---|---|---|
| Android product flavor / iOS scheme | `dev` | `staging` | `prod` |
| App id / bundle id | `com.guftagu.app.dev` | `com.guftagu.app.staging` | `com.guftagu.app` |
| API | localhost | staging | production |
| Signing | debug | upload key | release keystore |

Per-environment values come from `react-native-config` (`.env.dev`, `.env.staging`, `.env.prod`),
which bakes them into both native builds — **not** from a JS constants file. A JS file cannot
reach the native side, and the Agora app id and Firebase config are both needed there.

All three installable side by side, so a tester never has to guess which build they are on.

### 8.2 Android

Signed AAB, `minSdk 26`, `targetSdk 34`, Hermes enabled, R8 with ProGuard rules for Agora, Razorpay,
Firebase **and React Native itself** — the default RN ProGuard rules are the ones people forget, and
the symptom is a release build that crashes where debug does not.
Internal testing track → closed testing → production. Data-safety form completed (⚠ CI-05). Review
typically 1–3 days.

### 8.3 iOS

Xcode archive → TestFlight → App Store. `NSMicrophoneUsageDescription` and `NSCameraUsageDescription`
written in plain user-facing language — vague strings are a common rejection. Background audio mode
enabled. Sign in with Apple **required** because Google sign-in is offered (guideline 4.8). Privacy
nutrition labels completed. Review typically 1–7 days.

### 8.4 The review-notes package

Prepare before D50. Voice-social apps with virtual currency are reviewed carefully; a prepared
submission is the difference between one round and three.

Include: a demo account with coins pre-loaded and a second account to gift to · clear steps to reach a
live room (reviewers cannot find an empty room useful — **have a staffed room live during the review
window**) · where blocking, reporting and the community guidelines live · how moderation works and
who staffs it · an explanation of the coin economy and what coins can and cannot be exchanged for.

### 8.5 The IAP question

> **Apple guideline 3.1.1** requires in-app purchase for digital content consumed within the app.
> Coins purchased via Razorpay and spent on virtual gifts is precisely that pattern.

**This is [R-04](05-sprint-plan.md#r-04--app-store-review-and-iap) and it is not resolved by planning
— only by submitting.** StoreKit IAP is out of scope ([00 §8](00-overview.md#8-out-of-scope)); if
Apple requires it, it is a Change Request of roughly 5–8 developer-days plus a 30% commission that
changes the unit economics.

Mitigations available: submit early (D50) to get the answer inside the engagement · launch Android
first and let iOS follow · if required, implement IAP as a fast-follow CR. Raise it with the client in
**M1**, not M7 — they may already have a commercial position.

### 8.6 Force upgrade

`GET /app/config` returns `min_supported_version`. Below it, every API call returns
`426 APP_UPDATE_REQUIRED` and the app shows a blocking update screen. Necessary for the day a
security fix or a breaking API change ships.

---

## 9. Backup & recovery

| What | Method | Frequency | Retention | Restore target |
|---|---|---|---|---|
| MySQL | DO managed backup + PITR | Daily + continuous | 7 days PITR, 30 days daily | < 1 h |
| MySQL financial tables | Additional `mysqldump` to Vultr Object Storage | Daily | 1 year | < 2 h |
| Redis | RDB snapshot | Hourly | 24 h | Rebuild from MySQL if needed |
| Vultr Object Storage media | Versioning + cross-region replication | Continuous | Indefinite | Immediate |
| Code | GitHub | Every push | Indefinite | Immediate |
| Secrets | Encrypted vault export | On change | Indefinite | Immediate |

**RPO 1 hour · RTO 4 hours.**

**Restore drill: quarterly, and once before go-live.** Restore the production database to a scratch
instance, run the reconciliation job against it, confirm the ledger is intact. A backup that has never
been restored is a hypothesis, not a backup.

---

## 10. Runbook index

Full procedures in [08 §3](08-handover-amc.md#3-production-support-runbook):

| Situation | Runbook |
|---|---|
| API down | RB-01 |
| WebSocket down, rooms frozen | RB-02 |
| Queue backed up | RB-03 |
| Ledger drift detected | **RB-04** |
| Payment webhook failures | RB-05 |
| Agora outage | RB-06 |
| Database at capacity | RB-07 |
| Redis lost | RB-08 |
| Suspected security incident | RB-09 |
| Bad release, roll back | RB-10 |
