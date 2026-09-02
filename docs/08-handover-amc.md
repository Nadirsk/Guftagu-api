# 08 — Handover, Warranty & AMC

← [07 DevOps & Deployment](07-devops-deployment.md) · [ROADMAP](../ROADMAP.md)

Implements SLA §5.5 (documentation), §9 (maintenance) and §10 M7 (*source code handover and 6-month
warranty*).

---

## 1. M7 deliverables

| # | Deliverable | SLA reference | Accepted when |
|---|---|---|---|
| 1 | User Acceptance Testing completed | §10 M7a, §5.4c | Signed UAT document, zero open S1/S2 |
| 2 | Production deployment | §10 M7b | Live, monitored, backed up, smoke-tested |
| 3 | Admin training | §10 M7b | Session delivered, recording and slides handed over |
| 4 | Source-code handover | §10 M7c | Repository ownership transferred, credentials delivered |
| 5 | Technical documentation | §5.5a | API contract, DB schema, architecture — this suite |
| 6 | Admin and user manuals | §5.5b | Written, reviewed, delivered |
| 7 | Production support runbook | §5.5c | Written, walked through with the client |
| 8 | 6-month warranty commencement | §9.1 | Start date recorded and acknowledged in writing |

---

## 2. Handover kit

Delivered as a single package on D53, with a signed acceptance receipt.

### 2.1 Code and repository

- GitHub organisation ownership transferred, or the client's account added as owner
- All branches, tags and full history preserved — not a squashed snapshot
- `README.md` per app with setup instructions verified on a clean machine
- `.env.example` for each app, complete and current
- Database migrations that run clean from empty, plus seeders

### 2.2 Documentation

| Document | Location |
|---|---|
| Roadmap and milestones | `ROADMAP.md` |
| Overview, roles, scope boundary | `docs/00-overview.md` |
| Architecture and security | `docs/01-architecture.md` |
| Database schema and money rules | `docs/02-database-schema.md` |
| API contract | `docs/03-api-contract.md` + live OpenAPI at `/api/documentation` |
| Epic backlog and acceptance criteria | `docs/04-epic-backlog.md` |
| Sprint plan and risk register | `docs/05-sprint-plan.md` |
| Test strategy and UAT | `docs/06-testing-qa.md` |
| DevOps and deployment | `docs/07-devops-deployment.md` |
| This handover document | `docs/08-handover-amc.md` |
| Admin manual | `docs/manuals/admin-manual.pdf` |
| User manual | `docs/manuals/user-manual.pdf` |
| Production runbook | `docs/runbook/` |

### 2.3 Credentials

Delivered through a password manager or an encrypted channel, **never by email or chat**, with a
written receipt listing every item transferred:

DigitalOcean · GitHub · MySQL and Redis · Spaces · Agora · Razorpay · MSG91 · Firebase · Sentry ·
domain registrar and DNS · Apple Developer · Google Play Console · the AES data key and its rotation
procedure.

**Every credential the delivery team used is rotated after handover.** The client's copy becomes the
only copy.

### 2.4 Evidence artefacts

- UAT sign-off document with every case and its result
- Load-test report at the CI-08 targets ([06 §5](06-testing-qa.md#5-load-testing))
- Security-test report with the OWASP checklist and findings
- Accessibility audit report (WCAG 2.1 AA)
- Final reconciliation report showing zero ledger drift
- Known-issues list: every open S3 and S4 with owner and target date

### 2.5 Acceptance form

```
GUFTAGU V1.0 — HANDOVER ACCEPTANCE

Delivered by:  AaiBuzz India Pvt. Ltd.
Received by:   ______________________   Date: ____________

  [ ] 1. UAT completed and signed off
  [ ] 2. Production deployed and verified
  [ ] 3. Admin training delivered
  [ ] 4. Source code and repository transferred
  [ ] 5. Technical documentation received
  [ ] 6. Admin and user manuals received
  [ ] 7. Production runbook received and walked through
  [ ] 8. All credentials transferred and receipted

Warranty period:  6 months from ____________ to ____________

Known open items (S3/S4) attached:  Yes / No     Count: ______

Client signature: ______________________
AaiBuzz signature: _____________________
```

---

## 3. Production support runbook

Each runbook: symptom → diagnosis → resolution → escalation → prevention. Kept in `docs/runbook/`,
one file each, and rehearsed before go-live.

| ID | Situation | First check | Typical resolution |
|---|---|---|---|
| RB-01 | API down / 5xx spike | `/api/v1/health`, nginx and FPM status, Sentry | Restart FPM; if it recurs, roll back the last release |
| RB-02 | Rooms frozen, no realtime | Reverb process, Redis connectivity, LB sticky sessions | Restart Reverb; clients auto-resync via full-state snapshot |
| RB-03 | Queue backed up | Horizon dashboard, failed-jobs table | Scale workers; identify the poison job; retry after fixing |
| **RB-04** | **Ledger drift alert** | Reconciliation report, the offending user and transaction | **Freeze the affected wallet. Do not "correct" a balance directly. Identify the missing or duplicate ledger row, post a compensating entry with a note, and record an incident report.** |
| RB-05 | Payment webhooks failing | `payment_webhooks` status counts, Razorpay dashboard, signature errors | Fix and replay via the replay tool (GFT-329) — idempotency makes replay safe |
| RB-06 | Agora outage | Agora status page, token minting, client error codes | Show a service banner; rooms degrade to chat-only; do not close rooms |
| RB-07 | Database at capacity | Connections, slow-query log, disk | Kill runaway queries; resize; check for a missing index |
| RB-08 | Redis lost | Managed Redis status | Rooms rebuild from MySQL via `rooms:reconcile`; leaderboards rebuild from snapshots; expect brief degradation, no data loss |
| RB-09 | Suspected security incident | Audit logs, failed-login spikes, unusual permission grants | Follow the incident procedure: contain, preserve logs, assess, notify per DPDPA within 72 h if personal data is involved |
| RB-10 | Bad release | Sentry error rate, smoke test | Repoint `current` to the previous release ([07 §5.4](07-devops-deployment.md#54-rollback)) |

**RB-04 deserves emphasis.** The instinct on a balance discrepancy is to correct the balance. That
destroys the evidence and breaks the ledger chain permanently. Always post a compensating entry and
keep the history.

---

## 4. Training

**M7 D52 · 3 hours · recorded**

| Block | Duration | Audience |
|---|---|---|
| Platform tour and role model | 30 min | All |
| **Permission delegation** — creating admins, granting, scoping, revoking, reading the audit log | 45 min | Super Admin |
| User, room and moderation operations | 30 min | Admin, Moderator |
| Economy — rates, packages, withdrawals, reconciliation | 30 min | Admin, Finance |
| Agency, host and settlement operations | 20 min | Admin, Manager |
| CMS, campaigns and reports | 15 min | Admin, Manager |
| Support escalation and the runbook | 10 min | All |

Deliverables: session recording, slide deck, and a one-page quick-reference card per role.

The permission block gets the largest slot deliberately — it is the mechanism the client will use
most, it is the one that replaces the Moderator app (DEV-01), and it is the one where a mistake has
security consequences.

---

## 5. Warranty — 6 months free

Per SLA §9.1: *6 months free post deployment; all bugs and defects fixed at no additional cost within
SLA.*

**Period:** 6 months from the production go-live date recorded on the acceptance form.

### Covered

- Any defect where delivered behaviour does not match the acceptance criteria in
  [04](04-epic-backlog.md)
- Crashes, data errors, calculation errors, security defects
- Integration breakage caused by our implementation
- Performance regression below the agreed NFRs
- Deployment and configuration defects

### Not covered — these are Change Requests

- New features, or changes to agreed behaviour
- Anything in [00 §8 Out of scope](00-overview.md#8-out-of-scope)
- Breakage caused by a third party changing their API (Agora, Razorpay, MSG91, Firebase, Apple, Google)
- OS or store policy changes requiring app rework — **including an Apple IAP requirement**
  ([R-04](05-sprint-plan.md#r-04--app-store-review-and-iap))
- Problems caused by client-side configuration changes or direct database edits
- Content, artwork or copy changes
- Scaling work beyond the agreed concurrency target (CI-08)
- Server, infrastructure and third-party usage costs

### Response times

Per SLA §8.3 escalation matrix:

| Severity | Definition | Response | Target resolution |
|---|---|---|---|
| **L1 / S1** | Platform down, money incorrect, security breach | **4 hours** | 24 hours |
| **L2 / S2** | Core feature broken, no workaround | **8 hours** | 3 working days |
| **L3 / S3** | Feature impaired, workaround exists | **24 hours** | 10 working days |
| S4 | Cosmetic | Next release | Next release |

Reporting channel: email to the support address with severity, steps to reproduce, screenshots or a
recording, affected user id, and the timestamp with timezone. A `request_id` from the error response
makes diagnosis dramatically faster — the admin panel and the app both surface it.

---

## 6. Change Request process

Per SLA §9.3: *estimated via the CR process · written approval required before development · charged
at Rs. 475 per hour.*

### Flow

1. **Request** — client submits by email describing the desired outcome.
2. **Clarify** — one call if the requirement is ambiguous. No estimate is given on a call.
3. **Estimate** — written, within 3 working days, in the format below.
4. **Approve** — client confirms in writing. Work does **not** start before this.
5. **Schedule** — CR becomes tickets in a named release with dates.
6. **Deliver** — built, tested, deployed, demonstrated.
7. **Invoice** — actual hours, with any variance from the estimate explained.

### Estimate template

```
CHANGE REQUEST CR-____                              Date: __________

Requested by:      ____________________
Summary:           ____________________________________________

Scope of change
  In scope:        • ______________________________
  Out of scope:    • ______________________________

Effort
  Analysis & design          ___ h
  Backend                    ___ h
  Admin web                  ___ h
  Mobile                     ___ h
  Testing                    ___ h
  Documentation & deployment ___ h
  ─────────────────────────────────
  Total                      ___ h  ×  Rs. 475  =  Rs. ________

Impact
  Delivery timeline:   ___ working days from approval
  Affects release:     ____________
  Regression risk:     Low / Medium / High
  Dependencies:        ____________________

Assumptions
  • ______________________________

Validity: 30 days from the date above.

Client approval: ______________________   Date: __________
```

### Anticipated CRs

Named here so the conversation starts from a shared understanding rather than a surprise:

| Likely CR | Rough effort |
|---|---|
| Apple StoreKit IAP, if required by review ([R-04](05-sprint-plan.md#r-04--app-store-review-and-iap)) | 5–8 days |
| A third language | 3–5 days |
| Playable mini-games (ludo, spin-and-win, etc.) | 10+ days each |
| AI content moderation (audio transcription, NSFW detection) | 10–15 days |
| Live video broadcast / RTMP streaming | 15+ days |
| Zoom SDK alongside Agora | 5–8 days |
| Web version of the user app | 25+ days |
| Additional payment gateway | 4–6 days |
| Music, DJ mode or voice effects | 8–12 days |

---

## 7. AMC — Annual Maintenance Contract

Per SLA §9.2 and §9.4. Begins when the 6-month warranty ends.

### Included

- Server monitoring and uptime support
- Security patches and dependency updates
- Bug fixes and minor enhancements
- Backups and performance optimisation

### Interpretation, so expectations match

| "Minor enhancement" means | It does not mean |
|---|---|
| A configuration or copy change | A new feature or module |
| A new report column or filter on an existing report | A new report engine |
| A UI adjustment within an existing screen | A new screen or flow |
| Adding a gift category or VIP tier | A new gifting mechanic |
| Under ~4 hours of work | Anything larger — that is a CR |

### Commercial terms

- **Payment cycle:** quarterly, in advance, at the start of each quarter (SLA §9.4a)
- **Revision:** the annual charge is subject to revision in subsequent years (SLA §9.4b)
- **Excluded:** all third-party and infrastructure costs — DigitalOcean, Agora minutes, Razorpay fees,
  MSG91 credits, Firebase, Apple and Google developer fees. These are billed directly to the client by
  those vendors and are not part of the AMC.

### Monthly AMC report

Uptime percentage · incidents by severity with resolution times · patches and dependency updates
applied · backup verification result · **financial reconciliation status for every day of the month** ·
performance trend against the NFRs · recommendations.

---

## 8. Communication & governance

Per SLA §8, continuing through warranty and AMC.

| Item | Commitment |
|---|---|
| Progress calls | 2–3 conference calls per week during development — completed work, next plan, blockers |
| Formal channel | Email for all correspondence and approvals |
| Meetings | In person whenever required |
| Escalation | L1 within 4 hours · L2 within 8 hours · L3 within 24 hours |

**Anything that changes scope, cost or timeline is confirmed by email.** A verbal agreement on a call
is a plan; the email is the record. This protects both sides equally.

### Escalation contacts

| Level | Role | Contact |
|---|---|---|
| L1 | Support / on-call engineer | ⚠ to be filled at handover |
| L2 | Technical lead | ⚠ to be filled at handover |
| L3 | Project director | vijay@aaibuzz.com · +91-9579862371 |
| Grievance officer (IT Act) | ⚠ CLIENT INPUT (CI-05) — client appoints | published in-app and on the website |

---

## 9. Post-launch watchlist

Not contractual — the things that actually decide whether a voice-social platform survives its first
90 days. Reviewed on the monthly AMC call.

| Watch | Why | Action if it moves |
|---|---|---|
| **Agora minute spend** | The largest variable cost, and it scales with success | Review room idle-timeouts and listener-side subscription settings |
| **Ledger drift** | Must be exactly zero, every day | RB-04 immediately; never let one day slide |
| **Recharge success rate** | Below 90% usually means a payment-method or UX problem, not a gateway fault | Investigate by method and device |
| **Report resolution time** | IT Act requires 24 h action, 15 day resolution | Grant more Moderators the actioning permissions |
| **Room-to-listener ratio** | Many empty rooms means discovery is failing, not supply | Tune explore ranking and featuring |
| **D1 / D7 retention** | The honest measure of whether the gamification works | Revisit check-in rewards and event cadence |
| **Host churn** | Hosts leaving means the earn economy is not competitive | Review commission slabs and target incentives |
| **Withdrawal turnaround** | Slow payouts destroy host trust faster than any bug | Batch more frequently; automate approval below a threshold |
| **App store rating** | Voice-social apps live and die on the first 200 reviews | Watch for moderation complaints specifically |
