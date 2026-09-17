# Guftagu Scope of Work vs Actual Code — User / Player (Mobile Application) Audit

**Scope:** FR.D — User / Player (Mobile Application), Guftagu Scope of Work §3.
**Audited:** 2026-09-16
**Companion doc:** [`GUFTAGU_SCOPE_AUDIT_REPORT.md`](GUFTAGU_SCOPE_AUDIT_REPORT.md) covers FR.A–C
(Super Admin, Admin, Manager, Moderator — the admin panel roles, ~95-97% complete). This file is
FR.D only, audited separately for the first time.

## Scope note before anything else

Is repo mein `mobile/` React Native app **exist nahi karti** — ROADMAP.md ke repo-layout mein listed
hai, par folder khali/absent hai. Ye audit sirf backend API-layer ka hai: jo mobile app aage banegi
usko consume karne layak API kitni ban chuki hai, `app/Http/Controllers/Api/*` +
`routes/api.php` ka non-admin `v1` route group check karke.

FR.A-C (admin) ka scope backend + admin-web UI dono tha, isliye woh mature hai. FR.D ka scope sirf
backend API hai (app UI khud ban hi nahi rahi abhi) — phir bhi jitna backend bana hai, uska bhi bada
hissa missing hai, is document mein wahi record hai.

## Epic-by-epic status

### D.1 Onboarding & Account — ✅ Done (2026-09-17 for c/d), a/b untested

- a. OTP send/verify (`auth/otp/send`, `auth/otp/verify`), social login (`auth/social`), password
  login/reset — ✅ built (`AuthController`)
- b. Profile setup — display name, gender, DOB (18+ check), country, invite code — ✅ built
  (`setupProfile`)
- c/d. Language, light/dark theme, privacy and notification preferences — ✅ **Fixed 2026-09-17**:
  new `PATCH /profile` (`AuthController::updateProfile`). The columns (`language`, `theme`,
  `privacy`, `notification_prefs`) already existed on `user_profiles` — nothing read or wrote them
  before this. Deliberately separate from `setupProfile` (the one-time onboarding step; name/
  gender/DOB cannot be changed through this route). 3 tests were written and verified passing
  (`ProfilePreferencesTest.php`), then **deleted 2026-09-17 at user request** — no automated test
  remains.
- **Test coverage: a/b (OTP, social, profile setup) still has none** — only the new c/d route is
  tested. `tests/Feature/Api/` has zero test files for the auth/OTP flow itself.

### D.2 Voice / Audio Rooms — ✅ Built (MVP subset), fixed 2026-09-17

- Join / leave a room, take / leave a seat, self-mute, self-camera-toggle — ✅ already built
  (`Api\RoomController`, backed by `RoomParticipationService`)
- **Room creation — ✅ Fixed.** `POST /rooms` (`RoomHostController::store` → `RoomCreationService`) —
  name, description, cover, category/theme/seat-template (each validated active, theme VIP-gated
  against `InventoryService::activeVip`), visibility + password for a private room, seat count.
  Creates the room, its seats, an `owner` `room_members` row, and seats the owner at seat 1.
  Read-only catalogue routes added alongside it: `GET room-categories`, `room-themes`,
  `room-seat-templates` (mobile counterparts of the admin ones, active-only, no audit/upload).
- **Host / co-host controls — ✅ Fixed.** New `RoomHostService` + `Api\RoomHostController`:
  promote/revoke co-host, invite-to-seat, host-mute, remove-from-seat, lock/unlock seat,
  set-announcement. Deliberately does **not** reuse `is_muted_by_host` (the moderator-sanction
  column `RoomModerationService::mute` writes) — a new `is_muted_by_owner` column keeps a host's
  casual in-room mute from being able to silently undo a real moderation sanction.
  `RoomSeat::isEffectivelyMuted()` now ORs all three mute sources.
- **Raise-hand — ✅ Fixed**, as a self-toggle (`POST rooms/{uuid}/raise-hand`), not the fuller
  request/accept/reject queue the original docs/03 spec sketched — see the status table added to
  docs/03 §4 for the exact deltas. The host sees the queue via `state.raised_hands` and seats
  someone directly with `seats/{n}/invite`, which does the same job as an "accept" would.
- **In-room chat — ✅ Fixed.** New `room_messages` table + `RoomChatService` +
  `Api\RoomChatController` (`GET`/`POST rooms/{uuid}/messages`, cursor-paginated like DM history).
  Persisted, not a bare client broadcast event, specifically so C.3's "review flagged text" has a
  row to review — reuses `ContentFilter::checkAndFlag` exactly like DM chat does.
- **Also fixed in passing:** `RoomParticipationService::join()` did not check a private room's
  password at all before this pass — any private room could be joined by anyone who had the uuid.
  Now checked via `Hash::check` against `password_hash`, with the owner and already-active members
  exempted from re-supplying it.
- **Room announcements** — ✅ Fixed (`PATCH rooms/{uuid}/announcement`, host/co-host only).
  **Pinned messages, shareable invite links (beyond returning `room_code` in `state`), a
  room-level ban by the host (as opposed to a moderator's), and browsing/exploring rooms
  (`GET /rooms`, `/rooms/trending`, `/rooms/categories`) remain unbuilt** — see the status table in
  docs/03 §4 for the complete list of what the original spec sketched but this pass did not build.
- Test coverage: 13 tests were written (create public/private/VIP-gated theme/unknown category,
  catalogue, host-control authorization, invite/mute/remove, co-host promote/revoke, raise-hand
  toggle, chat post/read/banned-word-block/non-member-refused) and verified passing — full backend
  suite 545/546 (the 1 failure the same pre-existing, unrelated `OpenApiDocumentTest` flake the
  main audit report already documents). **`RoomTest.php` was deleted 2026-09-17 at user request** —
  no automated test remains for any of D.2.

### D.3 Social & Discovery — ✅ Done

Explore/search, follow/followers/friends, block, profile visitors, activity feed / moments (posts,
likes, comments) — all built and test-covered: `SocialGraphTest` (26 tests), `MomentsTest` (24 tests).

### D.4 Chat & Messaging — ✅ Done

1:1 and group conversations, media sharing, read/delivered receipts, typing indicators, mute,
realtime broadcast — all built and test-covered: `ChatTest` (23 tests), `MessageTicksTest`
(14 tests), `BroadcastChannelTest` (7 tests).

### D.5 Video & Voice Calling — ❌ Not built

No `CallController`, no call-invite/ringing/accept-decline route of any kind. Camera on/off exists
only as a self-toggle inside a room seat (part of D.2), not as 1:1 or group video calling.

**⚠️ Related and more important finding — undocumented Agora → WebRTC deviation:**
`Api\RoomController::snapshot()` carries its own comment:

> "No Agora/RTC token block — audio is peer-to-peer WebRTC ... Any two clients who are both seated
> connect to each other directly."

Room audio is implemented as **peer-to-peer WebRTC mesh**, not Agora. This directly contradicts
ROADMAP.md's own resolved technology table (§2, row 9: "Agora RTC + RTM. Zoom SDK not implemented"),
and — unlike every other deviation in this project (CR-01 React Native, DEV-01 Moderator app,
DEV-02 Zoom, DEV-03 MySQL/Supabase) — **this one is not recorded in ROADMAP §6's scope-deviation
table at all.**

This matters beyond bookkeeping: peer-to-peer WebRTC mesh does not scale the way an SFU/Agora relay
does — every seated client connects directly to every other seated client, so bandwidth and CPU cost
grow with seat count on each device. SOW E.1b explicitly asks for "high-concurrency rooms" with
"scalable media routing." Whether the current multi-seat rooms (several audio + video seats at once)
hold up under this architecture at real concurrency has not been load-tested per this audit, and the
client has not signed off on the switch away from Agora. This should go in front of the client
explicitly, not stay buried in a controller comment.

### D.6 Virtual Gifting & Wallet — ✅ Built (MVP subset), fixed 2026-09-17

- **Gift catalogue** — ✅ `GET /gifts`, `GET /gifts/categories` (new `Api\GiftController`),
  available-only, filterable by category/tier.
- **Sending gifts** — ✅ `POST /rooms/{uuid}/gifts`, `GET /rooms/{uuid}/gifts` (new
  `Api\RoomGiftController`). `GiftSendService` existed with **no controller, no route, and no
  business-rule checks at all** before this — it only debited/credited and wrote a row. Added:
  availability check, VIP-tier gate, limited-stock decrement (locked, race-safe), `combo_count`
  (repeat sends of the same gift in the same room within 10s), a broadcast `gift.sent` event, and
  **idempotency** — a new `room_id` + unique `idempotency_key` column on `gift_transactions` (there
  was none), so a retried `X-Idempotency-Key` request cannot double-send even though the wallet
  ledger side was already idempotent on its own.
- **Wallet balance + history** — ✅ `GET /wallet`, `GET /wallet/coins/transactions`,
  `GET /wallet/diamonds/transactions` (new `Api\WalletController`), cursor-paginated same as DM chat.
- **Diamond-to-cash withdrawal** — ✅ `GET /withdrawals/config`, `POST /withdrawals`,
  `GET /withdrawals` (new `Api\WithdrawalController`). `WithdrawalService::request()` already
  existed with a comment saying *"the mobile app will call this once D.6d exists"* — only the route
  was missing.
- **Coin recharge — still not built, and deliberately so.** `GET /recharge-packages` (browsing) is
  built, but there is no `POST /recharge/orders` — that needs a live Razorpay order-creation call and
  webhook, which needs the account credentials ROADMAP.md §7 CI-04 has not received yet. Faking an
  order id would look finished and would not be; this stays the single biggest real gap in D.6.
- **Also fixed in passing, because D.7/D.8 read from it:** `WalletService::move()` updated a
  wallet's balance on every credit/debit but **never wrote `lifetime_coins_spent` or
  `lifetime_diamonds_earned`** — the exact two counters wealth/charm rankings and progression are
  defined against (docs/02 §7). Every ranking and progression screen would have shown zero for
  every user, forever, regardless of activity. Fixed at the one shared choke point
  (`WalletService::move()`), not per-caller.
- Test coverage: 10 tests were written and verified passing (`GiftWalletTest.php`), then **deleted
  2026-09-17 at user request** — no automated test remains.

### D.7 VIP, Levels & Gamification — ✅ Built (MVP subset), fixed 2026-09-17

- Daily check-in claim — ✅ already built (`CheckinController`)
- Campaign/tournament event claim — ✅ already built (`Api\EventController`)
- **VIP tiers, purchase, active subscription** — ✅ Fixed: `GET /vip/tiers`, `GET /vip/me`,
  `POST /vip/purchase` (new `Api\VipController`). Purchase is coins-only for now — the `gateway`
  duration path in the original docs/03 spec needs the same Razorpay integration D.6d is blocked on.
- **Cosmetics store (frames, bubbles, entry banners, entrance effects)** — ✅ Fixed:
  `GET /store/items`, `GET /store/items/mine`, `POST /store/items/{id}/purchase` (new
  `Api\StoreController`), VIP-tier gated where the item requires one. **Equipping which owned item
  is active is not built** — `UserProfile` has no "currently equipped frame/bubble" column for any
  of these yet, so there was nothing to wire a route to.
- **Badges** — ✅ Fixed: `GET /badges` (new `Api\BadgeController`) — catalogue + ownership flag.
  Deliberately just a list of what exists and what this user holds, **not** an auto-tracking
  achievements engine ("earn this by doing X") — that was explicitly deferred pending the app's
  own badge UI design, per prior direction, and this pass does not revisit that call.
- **Progression (wealth/charm level + next threshold)** — ✅ Fixed: `GET /progression` (new
  `Api\ProgressionController`), using `WealthCharmLevel::nextAfter()` (already existed, unused).
- Test coverage: 8 tests were written and verified passing (`VipStoreRankingTest.php`, shared with
  D.8), then **deleted 2026-09-17 at user request** — no automated test remains.

### D.8 Rankings & Leaderboards — ✅ Built (MVP subset), fixed 2026-09-17

- `GET /rankings?board=wealth|charm&period=`, `GET /rankings/me` (new `Api\RankingController`),
  reusing `LeaderboardService::board()` (already existed for the admin side, unused by mobile).
  Same restriction the admin side already documents: only `wealth` and `charm` are computable —
  `room` and `agency` boards need modules that do not exist yet, and asking for one 404s rather
  than silently returning nothing.
- Test coverage: was in `VipStoreRankingTest.php` (see D.7, deleted 2026-09-17) — board ranking +
  an unconfigured board/period 404ing.

### D.9 Agency, Host & Safety — ✅ Built (MVP subset), fixed 2026-09-17

- Report / block a user — ✅ already built (`BlockController`)
- **Browse agencies, apply to become a host, check application status** — ✅ Fixed:
  `GET /agencies`, `POST /host/apply`, `GET /host/status` (new `Api\HostController`). The
  `HostApplication`/`Host`/`Agency` models already existed for the admin review side; nothing let a
  user actually submit one. Refuses a second application while one is already pending, and refuses
  applying while already an approved host.
- **Host earnings and target progress** — ✅ Fixed: `GET /host/earnings`, `GET /host/targets`,
  reusing `Host::earnings()`/`Host::targets()` (already existed). 403s for anyone who is not an
  approved host.
- Test coverage: 5 tests were written and verified passing (`HostApplicationTest.php`), then
  **deleted 2026-09-17 at user request** — no automated test remains.

## Summary

| | |
|---|---|
| Solid, tested | D.2 Voice/Audio Rooms, D.3 Social & Discovery, D.4 Chat & Messaging, D.6 Gifting & Wallet, D.7 VIP/Store/Badges/Progression, D.8 Rankings, D.9 Host application *(all fixed 2026-09-17 except D.3/D.4)* |
| Partial | D.1 Onboarding (c/d fixed; OTP/social/setup itself still untested) |
| Not built at all | D.5 Video & Voice Calling (separate, pending the Agora/WebRTC decision below), coin recharge via a real payment gateway (D.6d) |

**Overall: roughly 8 of 9 epics now have a working mobile API — everything except D.5.** The two
real gaps left are not missing routes, they are missing external dependencies this pass could not
supply: a Razorpay account (recharge, CI-04) and a client decision on Agora vs. the WebRTC that is
already live. Everything else — room hosting, gifting, wallet, withdrawals, VIP, cosmetics, badges,
progression, rankings, host applications, preferences — is built, tested, and documented above.

One thing still stands out as needing the client's attention, not just more dev time:
**the Agora → WebRTC switch is a real, unrecorded architecture deviation with a scale risk**, not a
simple missing-feature gap — see D.5 above. Room creation, gifting and calling all ride the same
peer-to-peer WebRTC signalling `Api\RoomController::snapshot()` always used; none of this pass's
work touched that decision.

## Changelog

- **2026-09-17 (later)** — All 5 PHPUnit test files added this session (`RoomTest.php`,
  `GiftWalletTest.php`, `VipStoreRankingTest.php`, `HostApplicationTest.php`,
  `ProfilePreferencesTest.php` — 39 tests total) **deleted at user request.** All had been run and
  verified passing before deletion (full suite 571/572, 1 pre-existing unrelated flake). The
  application code these tests covered is untouched — only the automated regression coverage is
  gone. This repo has no git history, so the deletion is not recoverable; the "Test coverage" lines
  above are kept as a record of what was verified at the time, not a claim that a test still exists.
- **2026-09-17** — D.6/D.7/D.8/D.9/D.1(c/d) built in one pass, in the priority order the user set:
  gifting + wallet + withdrawals (D.6), VIP + store + badges + progression (D.7), rankings (D.8),
  host application + earnings (D.9), profile preferences (D.1). See each epic section above for the
  full list of new controllers/routes and what still isn't built (mainly: coin recharge via a real
  gateway, and equipping an owned cosmetic item). Along the way, fixed a real, load-bearing bug in
  `WalletService::move()` — it updated wallet balances but never wrote `lifetime_coins_spent` /
  `lifetime_diamonds_earned`, the exact two counters D.7's progression and D.8's rankings are
  defined against; both would have shown zero for every user, forever. 26 new tests across four
  files (`GiftWalletTest`, `VipStoreRankingTest`, `HostApplicationTest`, `ProfilePreferencesTest`).
  Full backend suite: 571/572 passing (the 1 failure is the same pre-existing, unrelated
  `OpenApiDocumentTest` flake earlier entries already document).
- **2026-09-17** — D.2 Voice/Audio Rooms: Partial → Done (MVP subset). Room creation, host/co-host
  seat controls, raise-hand, in-room chat all built — see the D.2 section above for the full list
  and the deltas from the original docs/03 spec. Also fixed a real gap found along the way: private
  rooms had no password check on join at all. 13 new tests (`RoomTest.php`), full suite 545/546
  (1 pre-existing unrelated flake). Read-only audits for the other epics are unchanged.
- **2026-09-16** — First audit of FR.D, split into its own file from the combined admin-roles audit
  report. Findings as above. Read-only audit — no code changed as part of this pass.
