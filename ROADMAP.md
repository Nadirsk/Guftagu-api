# Guftagu V1.0 — Development Roadmap

**Client:** AaiBuzz India Pvt. Ltd., Mumbai
**Product:** Guftagu — voice-first social networking platform
**Source of truth:** *Guftagu Scope of Work / Services-Level Agreement V1.0* (10 pages, signed)
**Contracted delivery:** 53 working days · 2 developers · 7 milestones
**Document status:** v1.0 — awaiting client sign-off on the open items in [§7](#7-blockers--client-inputs-required)

---

## 1. What Guftagu is

A cross-platform voice-social product where users gather in multi-seat live audio rooms, talk, send
animated virtual gifts, climb wealth and charm rankings, and where hosts and agencies earn real money
from that engagement. It ships as:

| Surface | Stack | Users |
|---|---|---|
| **Mobile app** | Flutter (Android + iOS) | User / Player / Host |
| **Admin panel** | Vue 3 (web) | Super Admin, Admin, Manager, Moderator |
| **API + realtime** | Laravel 12 (PHP 8.2+), MySQL 8, Redis 7 | — |

> **Scope deviation, confirmed:** the SLA specifies a *dedicated Moderator mobile application* (§1, §7,
> §10 M6) and scopes C.1 as *"web and mobile"*. **This is dropped on client direction.** Moderation is
> delivered entirely inside the admin panel as a permission-gated role. See
> [§6 Scope deviations](#6-scope-deviations-from-the-signed-sla).

---

## 2. Technology decisions

The SLA leaves several choices open (`"e.g. Agora"`, `"MySQL / Supabase"`, `"Razorpay / integrated
gateway"`, `"MSG91 / provider"`, `"optional Zoom SDK"`). Each is resolved here to exactly one option so
development is unambiguous. Any change to this table is a Change Request.

| # | Component | SLA wording | **Resolved choice** |
|---|---|---|---|
| 1 | Admin web | Vue.js | **Vue 3 + Vite + TypeScript + Pinia + Tailwind + Element Plus** |
| 2 | Backend API | Laravel (PHP) | **Laravel 12, PHP 8.2+ (8.3 in production), Sanctum, Horizon, Reverb** |
| 3 | Mobile | Flutter | **Flutter 3.2x, Dart 3, Riverpod, go_router** |
| 4 | Database | MySQL / Supabase | **MySQL 8.0** (system of record) + **Redis 7** (hot state, queues) |
| 5 | Repository | GitHub private | **Single private monorepo**, branch protection on `main` + `develop` |
| 6 | Hosting | DigitalOcean | **DO Droplets + Managed MySQL + Managed Redis + Spaces + LB** |
| 7 | Design | Figma | **Figma** — design system originated in M1 |
| 8 | Push | FCM | **Firebase Cloud Messaging** |
| 9 | Voice / video | Agora (optional Zoom) | **Agora RTC + RTM**. Zoom SDK **not** implemented in V1 |
| 10 | Payments | Razorpay / gateway | **Razorpay** (UPI, cards, net banking, wallets) |
| 11 | SMS | MSG91 / provider | **MSG91** (OTP + transactional) |
| 12 | Object storage | DO Spaces / S3 | **DigitalOcean Spaces** (S3-compatible), CDN enabled |

**Repository layout** (monorepo at `C:\xampp\htdocs\Guftagoo`):

```
Guftagoo/
├── ROADMAP.md          ← you are here
├── docs/               ← the executable specification (read in order)
├── backend/            ← Laravel 12 API + realtime + admin BFF
├── admin-web/          ← Vue 3 admin panel
└── mobile/             ← Flutter user app
```

---

## 3. The document suite

These nine documents *are* the specification. Development is executed against them, not against the
PDF. Read in this order on day 1.

| # | Document | What it answers | Primary reader |
|---|---|---|---|
| 0 | [docs/00-overview.md](docs/00-overview.md) | What are we building, for whom, what is out of scope | Everyone |
| 1 | [docs/01-architecture.md](docs/01-architecture.md) | How the pieces fit; realtime strategy; security model | Backend, DevOps |
| 2 | [docs/02-database-schema.md](docs/02-database-schema.md) | Every table, column, index; Redis keys; money rules | Backend |
| 3 | [docs/03-api-contract.md](docs/03-api-contract.md) | Every endpoint, payload, error code, webhook | Backend, Mobile, Web |
| 4 | [docs/04-epic-backlog.md](docs/04-epic-backlog.md) | Every ticket with acceptance criteria | Everyone |
| 5 | [docs/05-sprint-plan.md](docs/05-sprint-plan.md) | Who builds what on which day; risk register | PM, Everyone |
| 6 | [docs/06-testing-qa.md](docs/06-testing-qa.md) | How we prove it works; UAT script | QA, PM |
| 7 | [docs/07-devops-deployment.md](docs/07-devops-deployment.md) | Git, CI, environments, deploy, store release | DevOps |
| 8 | [docs/08-handover-amc.md](docs/08-handover-amc.md) | Handover kit, warranty, AMC, CR process | PM, Client |

---

## 4. Milestones

Exactly as contracted in SLA §10 and §11 — **53 working days total, 2 developers**.

| M | Milestone | Days | Cumulative | Key deliverables |
|---|---|---:|---:|---|
| **M1** | Design & Prototype | 5 | 5 | UI/UX wireframes · clickable prototype · DB schema · architecture document |
| **M2** | Core Backend & Admin Panel | 8 | 13 | OTP/social auth · RBAC + permission delegation · Super Admin & Admin dashboards |
| **M3** | Audio/Video Rooms & Real-Time | 10 | 23 | Rooms · multi-seat mic · host controls · Agora · 1:1 & group video · in-room chat |
| **M4** | Virtual Economy & Payments | 8 | 31 | Coin/diamond wallet · gifting + animations · Razorpay recharge · payouts |
| **M5** | Social, VIP & Gamification | 8 | 39 | Profiles · follow · discovery · VIP tiers · levels · rankings · events · lucky draws |
| **M6** | Moderation, Agency & Notifications | 6 | 45 | Moderation console · agency & host management · FCM · reports · audit logs |
| **M7** | UAT, Go-Live & Handover | 8 | 53 | UAT · production deployment · admin training · source handover · warranty start |

Day-by-day allocation across Dev A (backend) and Dev B (mobile/web) is in
[docs/05-sprint-plan.md](docs/05-sprint-plan.md), together with entry/exit criteria and the risk
register.

### ⚠ The headline finding: scale

The detailed breakdown in [docs/04](docs/04-epic-backlog.md) totals **349 tickets = 1,922 hours = 240
developer-days**. Against the contracted **53 working days with 2 developers**:

| | |
|---|---|
| Work to be done | 240 developer-days |
| Capacity contracted | 53 days × 2 developers = 106 developer-days |
| 2 developers would finish in | **≈120 working days** (~5.5 months) |
| Developers needed for 53 days | **≈4.5, working in parallel** |

The estimates are bottom-up from the SLA's own user stories, with the Moderator app already removed,
and they are not padded. The pre-agreed descope levers recover ~48 hours — 2.5% — so they absorb a
slipped milestone but do not close this.

**Three options, with numbers, in [docs/05 §9 R-09](docs/05-sprint-plan.md#r-09--the-scale-gap):**
add developers (recommended — closest to the contracted date), extend the timeline (~5.5 months at the
current team size), or agree a reduced V1 (a defined MVP that does fit 53 days). Backend is the
bottleneck at 1,081 hours versus 770 for both clients combined, so extra capacity should go there
first.

**This belongs on the agenda at the M1 review, day 5.** Every milestone below is still planned to the
contracted 53 days — the commitment is honoured in the plan; the arithmetic is simply not hidden.

### Other headline risks

| Risk | Milestone | Mitigation |
|---|---|---|
| Agora + authoritative seat state + video is 10 days of work across 2 clients | M3 | Spike Agora on M1 day 4; ~3 days recovered from M6 held as buffer; descope group video (D.5b) first |
| Double-entry economy + live gateway + invoices + payouts in 8 days | M4 | Ledger design frozen in M1; Razorpay sandbox wired in M2; invoices can move to M7 if needed |
| Apple may require in-app purchase for coin recharge (guideline 3.1.1) | M7 | Submit on D50 for an answer inside the engagement; Android unaffected; IAP would be a CR of 5–8 days |
| Client business inputs (pricing, commission, KYC policy) arriving late | M4 | Configurable from day 1 — seeded with placeholders, no code change when real values land |

---

## 5. Epic traceability matrix

Every requirement in the SLA maps to exactly one epic, one milestone and one backlog section.
**34 epics from the SLA + 1 added** (A.11, arising from the Moderator-app removal) = **35 epics**.

### FR.A — Super Admin & Admin

| Epic | Title | Milestone | Backlog |
|---|---|---|---|
| A.1 | Authentication & Security | M2 | [→](docs/04-epic-backlog.md#a1--authentication--security) |
| A.2 | Dashboard & Analytics | M2 | [→](docs/04-epic-backlog.md#a2--dashboard--analytics) |
| A.3 | User Management | M2 | [→](docs/04-epic-backlog.md#a3--user-management) |
| A.4 | Room Management | M3 | [→](docs/04-epic-backlog.md#a4--room-management) |
| A.5 | Content Moderation & Safety | M6 | [→](docs/04-epic-backlog.md#a5--content-moderation--safety) |
| A.6 | Gift, VIP & Store Management | M4 | [→](docs/04-epic-backlog.md#a6--gift-vip--store-management) |
| A.7 | Economy, Payments & Settlements | M4 | [→](docs/04-epic-backlog.md#a7--economy-payments--settlements) |
| A.8 | Agency & Host Management | M6 | [→](docs/04-epic-backlog.md#a8--agency--host-management) |
| A.9 | Events, Games & Rankings | M5 | [→](docs/04-epic-backlog.md#a9--events-games--rankings) |
| A.10 | CMS, Reports & Audit Logs | M6 | [→](docs/04-epic-backlog.md#a10--cms-reports--audit-logs) |
| **A.11** | **Role & Permission Delegation** *(added)* | M2 | [→](docs/04-epic-backlog.md#a11--role--permission-delegation-added) |

### FR.B — Manager

| Epic | Title | Milestone | Backlog |
|---|---|---|---|
| B.1 | Operational Dashboard | M6 | [→](docs/04-epic-backlog.md#b1--operational-dashboard) |
| B.2 | Agency & Host Operations | M6 | [→](docs/04-epic-backlog.md#b2--agency--host-operations) |
| B.3 | Content & Event Operations | M5 | [→](docs/04-epic-backlog.md#b3--content--event-operations) |
| B.4 | User & Room Support | M6 | [→](docs/04-epic-backlog.md#b4--user--room-support) |
| B.5 | Reports | M6 | [→](docs/04-epic-backlog.md#b5--reports) |

### FR.C — Moderator *(delivered in the admin panel)*

| Epic | Title | Milestone | Backlog |
|---|---|---|---|
| C.1 | Live Room Monitoring | M6 | [→](docs/04-epic-backlog.md#c1--live-room-monitoring) |
| C.2 | In-Room Enforcement | M6 | [→](docs/04-epic-backlog.md#c2--in-room-enforcement) |
| C.3 | Reports & Content Review | M6 | [→](docs/04-epic-backlog.md#c3--reports--content-review) |
| C.4 | Policy Enforcement | M6 | [→](docs/04-epic-backlog.md#c4--policy-enforcement) |
| C.5 | Notifications & Escalation | M6 | [→](docs/04-epic-backlog.md#c5--notifications--escalation) |

### FR.D — User / Player (mobile)

| Epic | Title | Milestone | Backlog |
|---|---|---|---|
| D.1 | Onboarding & Account | M2 | [→](docs/04-epic-backlog.md#d1--onboarding--account) |
| D.2 | Voice / Audio Rooms | M3 | [→](docs/04-epic-backlog.md#d2--voice--audio-rooms) |
| D.3 | Social & Discovery | M5 | [→](docs/04-epic-backlog.md#d3--social--discovery) |
| D.4 | Chat & Messaging | M5 | [→](docs/04-epic-backlog.md#d4--chat--messaging) |
| D.5 | Video & Voice Calling | M3 | [→](docs/04-epic-backlog.md#d5--video--voice-calling) |
| D.6 | Virtual Gifting & Wallet | M4 | [→](docs/04-epic-backlog.md#d6--virtual-gifting--wallet) |
| D.7 | VIP, Levels & Gamification | M5 | [→](docs/04-epic-backlog.md#d7--vip-levels--gamification) |
| D.8 | Rankings & Leaderboards | M5 | [→](docs/04-epic-backlog.md#d8--rankings--leaderboards) |
| D.9 | Agency, Host & Safety | M6 | [→](docs/04-epic-backlog.md#d9--agency-host--safety) |

### FR.E — Common modules

| Epic | Title | Milestone | Backlog |
|---|---|---|---|
| E.1 | Real-Time Voice & Video Infrastructure | M3 | [→](docs/04-epic-backlog.md#e1--real-time-voice--video-infrastructure) |
| E.2 | Notification System | M6 | [→](docs/04-epic-backlog.md#e2--notification-system) |
| E.3 | Payments & Wallet Gateway | M4 | [→](docs/04-epic-backlog.md#e3--payments--wallet-gateway) |
| E.4 | Security & Privacy | M2 → M7 | [→](docs/04-epic-backlog.md#e4--security--privacy) |
| E.5 | Multilingual & Accessibility | M5 | [→](docs/04-epic-backlog.md#e5--multilingual--accessibility) |

---

## 6. Scope deviations from the signed SLA

Recorded here so nothing is silently dropped and nothing can be raised as undelivered at UAT.

| # | SLA says | Delivered as | Reason | Effect |
|---|---|---|---|---|
| **DEV-01** | "Dedicated Moderator application" (§1); Flutter "User **& Moderator**" (§7); C.1 "web **and mobile**"; "Moderator application" as an M6 deliverable (§10) | Moderator is a **role inside the Vue admin panel**, access driven by granted permissions. No second Flutter app. | Client direction | −3 to −4 days in M6, reallocated as buffer to M3/M4. All C.1–C.5 stories still delivered, on web. |
| **DEV-02** | "Agora (with **optional Zoom SDK**)" (§7) | Agora only | "Optional" in the SLA | None. Zoom is a CR if wanted. |
| **DEV-03** | "MySQL **/ Supabase**" (§7) | MySQL 8 + Redis 7 | Native Laravel fit; single vendor | None |
| **DEV-04** | D.3d activity feed / moments "**(as applicable)**" | In scope, but flagged as descope lever #1 if M5 slips | SLA hedge | Only if invoked; requires written client agreement |
| **DEV-05** | D.5c "**optional** beauty filters" | Agora basic beautification only; no third-party beauty SDK | "Optional" in the SLA | None |

---

## 7. Blockers — client inputs required

The specification is complete for everything the SLA defines. These are business and legal inputs the
SLA does not contain. Each appears as `⚠ CLIENT INPUT` at its point of use in the docs. **None block
starting** — M1 through M3 are fully specified and can proceed while these are collected.

| # | Input | Blocks | Needed by |
|---|---|---|---|
| CI-01 | Coin pricing, recharge packages, coin↔diamond rate, diamond→INR rate | M4 | before M4 day 1 |
| CI-02 | Commission slabs (platform / agency / host), VIP tier pricing and privileges | M4, M5 | before M4 day 1 |
| CI-03 | Withdrawal policy — minimum, frequency, KYC threshold, TDS/GST treatment | M4 | before M4 day 1 |
| CI-04 | Agora, Razorpay, MSG91, Firebase, DigitalOcean accounts and credentials | M2–M4 | M2 day 1 |
| CI-05 | GST number, legal entity details, T&C, privacy policy, community guidelines | M4, M7 | M7 |
| CI-06 | Gift artwork and animations (SVGA/Lottie), entrance effects, frames, badges | M4 | M4 day 1 |
| CI-07 | Brand direction / existing design system, or approval to originate one in M1 | M1 | day 1 |
| CI-08 | Concurrency targets — peak users, peak concurrent rooms, average seats per room | M3, M7 | M3 |
| CI-09 | Apple Developer and Google Play Console accounts | M7 | M7 day 1 |
| CI-10 | **Annexure B** payment schedule — referenced by SLA §10 but not present in the supplied PDF | — | before milestone billing |

---

## 8. Governance

| Item | Commitment (SLA §8, §9) |
|---|---|
| Progress calls | 2–3 conference calls per week — completed work, next plan, blockers |
| Formal channel | Email for correspondence and approvals; in-person as required |
| Escalation | L1 within 4 hours · L2 within 8 hours · L3 within 24 hours |
| Warranty | 6 months free post-deployment; all bugs and defects fixed within SLA at no cost |
| AMC | Annual — monitoring, uptime, security patches, dependency updates, bug fixes, backups, performance |
| New features | Change Request process, written approval required, **Rs. 475 per hour** |
| Payment cycle | Maintenance quarterly, in advance; annual charge revisable in subsequent years |

Details and templates in [docs/08-handover-amc.md](docs/08-handover-amc.md).

---

## 9. Day one checklist

1. Read `docs/00` → `docs/05` in order (about 2 hours).
2. Confirm the [technology table](#2-technology-decisions) and the
   [scope deviations](#6-scope-deviations-from-the-signed-sla) with the client, in writing.
3. Chase the [client inputs](#7-blockers--client-inputs-required) — CI-04 and CI-07 first.
4. Set up the repo and environments per [docs/07](docs/07-devops-deployment.md#2-repository--branching).
5. Start M1 per [docs/05](docs/05-sprint-plan.md#m1--design--prototype-5-days).
