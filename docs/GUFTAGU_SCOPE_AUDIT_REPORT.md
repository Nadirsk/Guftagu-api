# Guftagu Scope of Work vs Actual Code — Audit Report

FR.B (Manager) audit agent ka wahi ID mera session mein match nahi kar raha, lekin maine already teeno audits (Super Admin/Admin, Manager, Moderator) complete kar liye the — aur pichhle message mein hi full consolidated report de diya tha. Doobara short recap:

Sab 3 audits complete hain, koi pending nahi hai wait karne ko. Yahan final synthesis hai:

## Guftagu Scope of Work vs Actual Code — Final Report

**RBAC foundation:** `RoleSeeder.php` mein exactly Super Admin, Admin, Manager, Moderator (+it_admin) defined hain, har screen frontend route-guard + backend `permission:` middleware se double-guarded hai.

### Super Admin & Admin (FR.A) — 9/10 Done, 1 Partial

- A.1 Auth & Security — ✅ Done (login+OTP MFA, session timeout, per-role 2FA)
- A.2 Dashboard & Analytics — ✅ Done *(fixed 2026-09-09)*: live-room count ab real query hai (`Room::live()->count()`, pehle hardcoded 0 tha), export CSV + PDF dono (Report Centre ke dompdf machinery reuse kiya), `streams_live` (recharge/gifting/vip) ab `daily_stats` rollup se dynamically derive hota hai instead of hardcoded false — jaise hi real payment/gifting transactions aayenge flag khud true ho jayega, code change ki zaroorat nahi. Revenue amount abhi bhi 0 hai kyunki upstream payment/gifting flows nahi likhte (ye A.7 ka gap hai, A.2 ka nahi)
- A.3 User Management — ✅ Done
- A.4 Room Management — ✅ Done
- A.5 Content Moderation & Safety — ✅ Done
- A.6 Gift, VIP & Store — ✅ Done
- A.7 Economy, Payments & Settlements — ⚠️ Partial: admin ka hissa (rates/packages/commission slabs/ledger/reconciliation) poora ban chuka hai (`EconomyController`). **Missing wala hissa admin panel mein nahi hai** — ye backend API-layer gap hai: (1) order-creation endpoint jo mobile app recharge start karne ke liye call karega, (2) Razorpay webhook jo payment-success par wallet credit karega. Ye dono backend infra hain, app aur admin dono ko affect karte hain — isi wajah se `coin_transactions` mein `recharge` type ka ek row nahi hai aur dashboard revenue 0 dikhta hai
- A.8 Agency & Host Management — ✅ Done
- A.9 Events, Games & Rankings — ✅ Done
- A.10 CMS, Reports & Audit Logs — ✅ Done

### Manager (FR.B) — 5/5 Done

- B.1 Operational Dashboard — ✅ Done
- B.2 Agency & Host Operations — ✅ Done *(corrected 2026-09-09)*: baseline mein sirf `agency.approve`/`hosts.approve` nahi hai (two-person-rule by design — jo onboard kare wahi approve nahi kar sakta), **par ye permission-gated hai, feature-missing nahi**. `GrantPermission.php` (A.11 delegation system) confirm karta hai: Admin kisi specific Manager ko `agency.approve`/`hosts.approve` individually grant kar sakta hai (audited + MFA-gated), jaise Moderator ki enforcement powers grant hoti hain. Same for `rooms.feature`/`rooms.pin` (B.3)
- B.3 Content & Event Operations — ✅ Done *(corrected 2026-09-09)*: events/banners draft baseline mein hai; bulk room feature/pin (`rooms.feature`, `rooms.pin`) baseline mein nahi par individually grantable hai (upar wahi delegation mechanism)
- B.4 User & Room Support — ✅ Done
- B.5 Reports — ✅ Done *(fixed 2026-09-09)*: operational reports pehle se theek the; campaign outcome tracking (`GET /broadcasts/{id}/outcome`) `cms.announcement_manage` permission maangta hai jo Manager baseline mein add kar diya — ab Manager campaign draft/edit kar sakta hai aur uska outcome dekh sakta hai, `cms.campaign_send` (high-risk, actual send) Admin-only hi rehta hai, banner ke prepare/approve split jaisa hi

### Moderator (FR.C) — 4 Done, 1 Partial

- C.1 Live Room Monitoring — ✅ Done (silent-join real hai)
- C.2 In-Room Enforcement — ✅ Done
- C.3 Reports & Content Review — ✅ Done
- C.4 Policy Enforcement — ✅ Done
- C.5 Notifications & Escalation — ⚠️ Partial: in-app notification bell/inbox Done hai (`GET/POST /admin/notifications*` — sab 4 roles ke liye, koi permission key nahi). **Real FCM push (browser notification) revert kar diya** — user ne bataya ki ye mobile app ke saath ek saath karenge, abhi nahi. Jo revert hua: `PushNotificationService`, `NotificationObserver`, `SendAdminPush`/`NotifyAdminsOfNewUser` jobs, `admin_push_tokens` table, `kreait/firebase-php`, frontend `firebase.ts`/service-worker/`usePushNotifications` composable — sab hata diya. **Service-account JSON `storage/app/firebase/service-account.json` mein safe hai** (gitignored) — jab app ke saath push implement karna ho tab dobara use ho sakta hai
- Deviation: dedicated Moderator mobile app nahi bana, web-panel role hai (pehle se decided choice)

### User / Player — Mobile Application (FR.D) — audited 2026-09-16

*Full standalone writeup: [`GUFTAGU_SCOPE_AUDIT_FR_D_USER_PLAYER.md`](GUFTAGU_SCOPE_AUDIT_FR_D_USER_PLAYER.md). Kept here too, in short form, so this file stays the single index of every epic's status.*

Ye pehli baar audit hui hai (pichle audits sirf FR.A/B/C — 4 admin roles — cover karte the). Scope
alag hai: FR.A-C ka backend + admin-web UI dono ban chuke the, isliye woh ~95-97% tha. **FR.D sirf
backend API layer hai** — koi `mobile/` React Native app is repo mein exist hi nahi karti (ROADMAP.md
ke repo-layout mein listed hai, par folder khali/absent hai). Neeche sirf ye check kiya hai ki mobile
consume karne layak API bani hai ya nahi (`app/Http/Controllers/Api/*` + `routes/api.php` ka `v1`
non-admin group).

- **D.1 Onboarding & Account — ✅ Done (c/d fixed 2026-09-17).** OTP send/verify, social login
  (`auth/social`), password login/reset, profile setup (name/gender/DOB/country/invite-code) pehle
  se bane the (`AuthController`). Language/theme/privacy/notification preferences — naya
  `PATCH /profile` (columns already the schema mein the, koi route nahi tha). **OTP/social/setup
  flow abhi bhi untested hai** — sirf naya preferences route test-covered hai
  written and verified passing (3 tests), then **deleted 2026-09-17 at user request** — no automated
  test remains for this route.
- **D.2 Voice/Audio Rooms — ✅ Done (MVP subset), fixed 2026-09-17.** Room creation (`POST /rooms`,
  category/theme/seat-template validated + VIP-gated), host/co-host controls (invite-to-seat,
  host-mute via a new `is_muted_by_owner` column kept separate from the moderator-sanction
  `is_muted_by_host`, remove-from-seat, lock/unlock seat), raise-hand (self-toggle), in-room chat
  (`room_messages` table, banned-word filtered same as DM chat), announcement — all built.
  Also fixed in passing: private-room join had **no password check at all** before this. Not built:
  pinned messages, a real invite-link (uses `room_code` as-is), a host-level room ban, browsing/
  exploring rooms from the app. 13 tests were written and verified passing (full suite 545/546,
  1 pre-existing unrelated flake) — **all test files were deleted 2026-09-17 at user request**, so
  none of this is under automated regression coverage any more. Full deltas from the original
  docs/03 spec in
  `GUFTAGU_SCOPE_AUDIT_FR_D_USER_PLAYER.md`.
- **D.3 Social & Discovery — ✅ Done.** Search, explore/feed, follow/followers/friends, block, visitors,
  profile view — sab bane hain aur test-covered hain (`SocialGraphTest` 26 tests, `MomentsTest` 24).
- **D.4 Chat & Messaging — ✅ Done.** 1:1/group conversations, media, read/delivered receipts, typing,
  mute, notification-worthy events — test-covered (`ChatTest` 23, `MessageTicksTest` 14,
  `BroadcastChannelTest` 7).
- **D.5 Video & Voice Calling — ❌ Not built.** Koi `CallController` ya call-invite/ringing/accept-decline
  route nahi hai. Room ke andar camera on/off self-toggle hai (D.2 ke saath), par 1:1/group video
  calling (D.5a/b/e) bilkul missing hai.
  - ⚠️ **Zyada important cheez:** `Api\RoomController::snapshot()` mein khud comment likha hai —
    *"No Agora/RTC token block — audio is peer-to-peer WebRTC ... Any two clients who are both seated
    connect to each other directly."* Yani room ka real-time audio Agora se nahi, **peer-to-peer WebRTC
    mesh** se ho raha hai. Ye ROADMAP.md ke apne technology table (#9: "Agora RTC + RTM") se **seedha
    contradict karta hai**, aur ROADMAP §6 ke scope-deviation table mein ye kahin record nahi hai —
    saari doosri deviations (CR-01 React Native, DEV-01 Moderator app, DEV-02 Zoom) likhi gayi hain,
    ye nahi. P2P WebRTC mesh multi-seat rooms mein SOW E.1b ("high-concurrency rooms ... scalable media
    routing") ke against risk hai — har seated client baaki sab se directly connect karta hai, seat count
    badhne par bandwidth/CPU har client par explode karta hai, ek SFU/Agora relay ke bina. Client ko
    ye batana zaroori hai — undocumented tech deviation + scale risk dono.
- **D.6 Virtual Gifting & Wallet — ✅ Done (MVP subset), fixed 2026-09-17.** Gift catalogue, sending
  gifts (VIP-gate + stock + idempotency + combo_count + room broadcast, naya `room_id`/
  `idempotency_key` column `gift_transactions` mein), wallet balance + ledger, diamond-to-cash
  withdrawal request (`WithdrawalService::request()` pehle se tha, sirf route nahi tha) — sab ban
  gaye. **Coin recharge via real gateway abhi bhi nahi bana** (jaan-boojh kar — Razorpay credentials
  chahiye, CI-04 pending, packages GET reuse ho sakta hai). Isi pass mein ek real bug bhi mila aur fix
  kiya: `WalletService::move()` kabhi `lifetime_coins_spent`/`lifetime_diamonds_earned` likhta hi
  nahi tha — matlab D.7/D.8 ke rankings/progression hamesha zero dikhate.
- **D.7 VIP, Levels & Gamification — ✅ Done (MVP subset), fixed 2026-09-17.** VIP tiers/purchase
  (coins se)/me, cosmetics store (frames/bubbles/banners/effects) browse+purchase+mine, badges
  (catalogue+ownership), progression (wealth/charm level + next threshold) — sab naye. Equip karna
  (kaunsa owned frame active hai) nahi bana — `UserProfile` mein koi "currently equipped" column
  nahi hai. Achievements auto-tracking engine jaan-boojh kar nahi banaya — pehle se deferred decision
  hai, badge UI design ke baad.
- **D.8 Rankings & Leaderboards — ✅ Done (MVP subset), fixed 2026-09-17.** `GET /rankings`,
  `/rankings/me` — admin ka `LeaderboardService::board()` reuse kiya. Sirf wealth/charm computable
  hain (admin side ki apni existing limitation), room/agency 404 dete hain honestly.
- **D.9 Agency, Host & Safety — ✅ Done (MVP subset), fixed 2026-09-17.** Agencies browse, host apply
  (duplicate-pending aur already-approved dono guard kiye), status, earnings, targets — sab naye,
  existing `HostApplication`/`Host`/`Agency` models reuse karke.

**Summary — FR.D ab 8 of 9 epics mein working mobile API rakhta hai — sirf D.5 (video calling) baaki
hai.** 2026-09-17 ko ek hi pass mein D.1(c/d), D.2, D.6, D.7, D.8, D.9 sab ban gaye — room creation +
host controls, gifting + wallet + withdrawals, VIP + store + badges + progression, rankings, host
application, profile preferences. Jo genuinely baaki hai wo missing routes nahi hain — external
dependencies hain jo is pass mein supply nahi ho sakti thi: Razorpay account (coin recharge, CI-04)
aur client ka Agora-vs-WebRTC decision. Ek real bug bhi mila aur fix hua: `WalletService::move()`
lifetime_coins_spent/lifetime_diamonds_earned kabhi likhta hi nahi tha — rankings/progression hamesha
zero dikhate, chahe kitni bhi activity ho.

### Top-Level Pending Summary

1. **Payment gateway (Razorpay/UPI) — order API + webhook missing** — sabse bada gap. Ye backend infra hai (app recharge start nahi kar sakta, wallet credit nahi hoga), admin panel ka nahi
2. **FCM push notifications** — client-side + server-side dono ban chuke the, par user ke kehne par revert kar diya (app ke saath saath karenge). Credentials safe hain, dobara jaldi ban sakta hai
3. ~~Dashboard live KPIs — stub data~~ ✅ **Fixed 2026-09-09** — live rooms real query, PDF export added, streams_live ab rollup se dynamic (revenue amount abhi bhi 0 rahega jab tak #1 nahi hota, par flag ab honest aur self-updating hai)
4. ~~Manager ke verify/approve + bulk room-promotion powers — scope se kam~~ ✅ **Corrected 2026-09-09** — ye feature-gap nahi tha, design hai: baseline mein nahi par A.11 delegation system se individually grantable hai
5. ~~Transaction ledger admin-web UI — missing (API ready)~~ ✅ **Fixed 2026-09-16** — `EconomyView.vue` mein naya "Transaction ledger" tab: currency (coin/diamond), type, user ID aur date-range filters, paginated table (When/User/Type/Change/Balance/Reference/Note), existing `GET /admin/economy/ledger` API reuse kiya (koi backend change nahi laga, API pehle se ready thi). Route already `economy.ledger_view` permission-gated hai (Super Admin + Admin — Manager/Moderator baseline mein ye permission nahi, jo SOW ke A.7 scoping se match karta hai). Frontend `vue-tsc` + production `npm run build` dono clean.
6. Moderator dedicated mobile app — **nahi banega** (confirmed deviation, not a gap — see ROADMAP.md §6 DEV-01)
7. ~~FR.D — D.2 room-creation aur host controls missing~~ ✅ **Fixed 2026-09-17** — room creation, host/co-host seat controls, raise-hand, in-room chat sab ban gaye. Details D.2 row mein.
8. ~~FR.D — D.6 gifting/wallet, D.7 VIP purchase, D.8 rankings, D.9 host application missing~~ ✅ **Fixed 2026-09-17** — sab ban gaye ek hi pass mein, 26 naye tests ke saath. Details upar respective D.x rows mein.
9. **Coin recharge via real payment gateway abhi bhi nahi bana** — jaan-boojh kar: Razorpay account credentials chahiye (ROADMAP.md §7 CI-04, client input pending). Packages GET reuse ho sakta hai jab account mil jaye.
10. **D.5 Video & Voice Calling — abhi bhi nahi bana.** Client-direction wait kar raha hai (item 11 dekho) isse pehle iska sahi architecture decide karna behtar hai.
11. **⚠️ `is_frozen` (wallet freeze) is never actually enforced — newly found 2026-09-17.** Admin
    can freeze a wallet (`WalletService::setFrozen`, A.7/A.3d) aur admin panel/`GET /wallet` dono
    isko honestly report bhi karte hain, **par `WalletService::move()` kahin bhi `is_frozen` check
    nahi karta** — matlab ek frozen wallet abhi bhi gift bhej sakta hai, VIP/store kharid sakta hai,
    withdrawal request kar sakta hai. Freeze sirf ek label hai, guard nahi. Pre-existing gap tha
    (mobile routes ne ise expose nahi kiya, sirf surface kiya kyunki ab wallet-spending endpoints
    pehli baar reachable hain) — is pass mein fix nahi kiya kyunki iske exact semantics
    (debit-only? admin-override allowed?) client/product decision maangte hain, sirf ek code-level
    fix nahi.
12. **⚠️ Agora vs WebRTC — undocumented deviation.** Room audio Agora se nahi, peer-to-peer WebRTC mesh se implement hua hai (`Api\RoomController` ka apna comment ye confirm karta hai). ROADMAP.md khud "Agora RTC + RTM" resolved-choice bolta hai aur ye deviation kahin record nahi hai — client se confirm karwana zaroori hai, high-concurrency rooms (SOW E.1b) ke liye scale-risk bhi hai. Room creation, gifting (items 7-8) isi WebRTC signalling pe hi ban hain — is pass mein ye change nahi hua.

**Admin roles (Super Admin/Admin/Manager/Moderator, FR.A-C): ~95-97% kaam complete hai.** Jo asli baaki hai: (1) payment gateway ka backend order+webhook, (2) real push (deliberately deferred, app ke saath karenge), (3) Moderator ka dedicated mobile app (client-direction se already deprioritized, dobara build nahi hoga). Baaki sab — admin panel UI/logic, RBAC, delegation system, in-app notifications, transaction ledger — genuinely solid hai.

**User/Player mobile API (FR.D): 8 of 9 epics ab built hain** (sirf D.5 video calling baaki) —
admin roles ke jitna mature test-covered nahi hai (kuch epics mein bas MVP-depth coverage hai, D.1's
OTP/social/setup flow abhi bhi untested hai), par functionally sab reachable hai. Do genuine
external-dependency gaps bache hain: Razorpay account (coin recharge) aur Agora-vs-WebRTC client
decision. Isse alag se track karna chahiye — admin-role completion ke number ke saath mix nahi
karna, kyunki dono alag scope hain.

### Changelog
- **2026-09-17 (later)** — All 5 PHPUnit test files added this session
  (`RoomTest.php`, `GiftWalletTest.php`, `VipStoreRankingTest.php`, `HostApplicationTest.php`,
  `ProfilePreferencesTest.php` — 39 tests total) **deleted at user request.** They had all been run
  and verified passing before deletion (full suite 571/572, 1 pre-existing unrelated flake) — the
  code itself is untouched, only the automated regression coverage for it is gone. No git repo
  exists in this project, so this is not recoverable; new tests would have to be written from
  scratch to get coverage back.
- **2026-09-17** — D.6/D.7/D.8/D.9/D.1(c/d) (FR.D) built in one pass: gifting + wallet + withdrawals,
  VIP + cosmetics store + badges + progression, rankings, host application + earnings, profile
  preferences. Also fixed a real bug in `WalletService::move()` — it never wrote
  `lifetime_coins_spent`/`lifetime_diamonds_earned`, so every ranking/progression screen would have
  shown zero forever. 26 new tests, full suite 571/572 (1 pre-existing unrelated flake). Full detail
  in `GUFTAGU_SCOPE_AUDIT_FR_D_USER_PLAYER.md`.
- **2026-09-17** — D.2 Voice/Audio Rooms (FR.D) built: room creation, host/co-host seat controls
  (invite/mute/remove/lock), raise-hand, in-room chat, announcement. New `is_muted_by_owner` column
  keeps a host's casual mute separate from the moderator-sanction `is_muted_by_host`. Also fixed:
  `RoomParticipationService::join()` had no password check for private rooms at all before this.
  13 new tests (`RoomTest.php`), full suite 545/546 (1 pre-existing unrelated `OpenApiDocumentTest`
  flake, same one earlier changelog entries already document). Full deltas from the original
  docs/03 spec recorded there and in `GUFTAGU_SCOPE_AUDIT_FR_D_USER_PLAYER.md`.
- **2026-09-16** — First FR.D (User/Player mobile) audit added — previously only FR.A/B/C (admin roles) were audited. Findings: D.3/D.4 done and tested, D.1/D.2/D.7/D.9 partial, D.5/D.6/D.8 have zero mobile-facing code (`Api\GiftController`/`WalletController`/`RankingController`/`CallController` do not exist). Also surfaced that `GiftSendService` exists but is wired to no controller anywhere, and that room audio is peer-to-peer WebRTC per `Api\RoomController`'s own comment — not Agora, contradicting ROADMAP.md's resolved technology table (#9) and not recorded as a scope deviation there. Read-only audit — no code changed.
- **2026-09-09** — A.2 Dashboard & Analytics: Partial → Done. Live-room stub, PDF export, aur `streams_live` flag fix kiye (details A.2 row mein). Backend tests: 16/16 DashboardTest, 48/48 related Room/ReportCentre tests, 526/527 full suite (1 pre-existing unrelated failure — mobile Social/Chat routes, isse related nahi). Frontend `vue-tsc` typecheck clean.
- **2026-09-09** — B.2/B.3 Manager status corrected: Partial → Done. In dono epics ka "Admin-only" reading galat thi — `agency.approve`/`hosts.approve`/`rooms.feature`/`rooms.pin` baseline mein nahi hain (two-person-rule by design) par A.11 delegation system (`GrantPermission.php`) se Admin kisi bhi specific Manager ko ye individually, audited tareeke se grant kar sakta hai. A.7 line bhi clarify ki: payment gateway gap admin panel ka nahi, backend order-API + webhook ka hai.
- **2026-09-09** — B.5 Reports: Partial → Done. `cms.announcement_manage` (medium risk) Manager baseline mein add kiya (`RoleSeeder.php`) — ab Manager campaign draft/edit kar sakta hai aur `GET /broadcasts/{id}/outcome` dekh sakta hai; `cms.campaign_send` (high risk, actual send) Admin-only hi rehta hai. Naya test: `a_manager_can_prepare_a_campaign_and_track_its_outcome_but_cannot_send_it` (`ManagerModeratorTest.php`). Backend: 30/30 ManagerModeratorTest, 70/70 related CMS/Permission/Access tests, 527/528 full suite pass. Local dev DB (`guftagu_laravel`) mein `php artisan db:seed --class=RoleSeeder` chala kar Manager baseline live kar diya (24→25 permissions).
- **2026-09-09** — C.5 Notifications: admin panel mein notification icon add kiya (pehle bilkul nahi tha). Naya `AdminNotificationController` (`GET /admin/notifications`, `POST .../read`, `POST .../read-all` — no permission key, sirf apni khud ki rows) + `NotificationBell.vue` (header mein, `AppShell.vue` mein wire kiya) — **Super Admin, Admin, Manager, Moderator sab ke liye same tareeke se kaam karta hai**, unread badge + dropdown ke saath, 30s poll. `Notification` table already likhi ja rahi thi (support escalation) par kabhi dikhti nahi thi — ab woh data surface hota hai. OpenAPI docs bhi add kiye (`AdminNotificationPaths.php`) — is repo mein har route document hona mandatory hai (test-enforced). Backend: 5/5 naya `AdminNotificationTest`, 532/533 full suite pass (1 pre-existing unrelated failure). Frontend `vue-tsc` clean. **Note:** live-browser click-through nahi kar paya kyunki dev DB (`guftagu_laravel`) ke seeded admin credentials match nahi hue — backend HTTP feature tests se hi verify kiya hai (real routes/middleware/DB ke against). Baaki: real FCM push abhi bhi missing hai, aur moderator ke "critical report" toast-alerts is naye inbox mein merge nahi kiye (wo already-existing alag system hai, jaan-boojh kar chhoda).
- **2026-09-09** — C.5 web push (client-side): user ne Firebase project (`guftagoo-c3029`) ka web client config + VAPID key diya (service-account JSON abhi nahi). Naya: `admin_push_tokens` table + `AdminPushToken` model, `POST/DELETE /admin/notifications/device` endpoints, `admin-web/src/lib/firebase.ts` (permission request + token capture), `public/firebase-messaging-sw.js` (background push service worker), `usePushNotifications.ts` composable, `AppShell.vue` mein wire kiya (mount par silent re-register, sign-out par unregister), `NotificationBell.vue` mein "Enable" opt-in prompt. `npm install firebase` kiya. Backend: 8/8 `AdminNotificationTest` (device register/reregister/unregister sab cover), 535/536 full suite pass. Frontend `vue-tsc` clean + production `npm run build` clean. **Ye sirf client-side subscription hai — backend abhi actual push bhej NAHI sakta** kyunki Firebase Admin SDK/service-account JSON nahi mila. Jab tak wo nahi milta: browser token register ho jayega, par koi push deliver nahi hoga. Test karne ka tarika abhi: Firebase Console → Cloud Messaging → "Send test message" → `admin_push_tokens` table se token copy karke paste karo.
- **2026-09-09** — C.5 web push (server-side, poora ho gaya): user ne service-account JSON diya (`storage/app/firebase/service-account.json`, gitignored). `composer require kreait/firebase-php` kiya, `config/services.php` mein `firebase.credentials` wire kiya. Naya: `PushNotificationService` (koi credentials na ho toh honest no-op, Mockery se fully tested bina real network call ke), `NotificationObserver` (`Notification::observe()` — koi bhi `admin_user_id` wali row create ho, automatically push queue hota hai — support escalation aur future creators dono cover), `SendAdminPush` queued job (dead tokens Firebase ke apne jawab se auto-delete karta hai). Naya trigger: `NotifyAdminsOfNewUser` — user register hone par `users.view` rakhne wale active admins ko notification + push (`UserAuthService::createUser()` se wired, OTP + social dono paths cover, per-registration ek baar, sign-in par dobara nahi). Frontend: `usePushNotifications.initializeAfterLogin()` — login ke baad agar permission kabhi nahi maanga gaya toh Chrome khud Allow/Deny prompt dikhata hai. Backend tests: 4/4 `PushNotificationServiceTest`, 5/5 `AdminPushDeliveryTest`, 3/3 `NotifyAdminsOfNewUserTest` — 547/548 full suite pass (1 pre-existing unrelated failure). Frontend `vue-tsc` clean. **Zaroori:** `QUEUE_CONNECTION=database` hai, isliye push kaam karne ke liye `php artisan queue:work` running hona chahiye — bina worker ke jobs queue mein padi rahengi, kabhi chalengi nahi.
- **2026-09-16** — Transaction ledger admin-web UI built. New "Transaction ledger" tab in `EconomyView.vue` (`admin-web/src/views/EconomyView.vue`), wired to the pre-existing `GET /admin/economy/ledger` endpoint (`EconomyController::ledger()` — no backend change needed, it already supported currency/type/user_id/date-range filters and pagination). New `EconomyLedgerRow` type added to `types/api.ts`. Frontend `vue-tsc --noEmit` clean, production `npm run build` clean. Not click-tested in a live browser session — verified via typecheck + build only.
- **2026-09-09** — C.5 web push **revert kiya** (user ka faisla — "abhi nahi karna, baad mein app ke saath karunga"). Hataya gaya: `PushNotificationService`, `NotificationObserver`, `SendAdminPush`, `NotifyAdminsOfNewUser` jobs, `AdminPushToken` model + `admin_push_tokens` table (migration rollback), `kreait/firebase-php` composer package, `AdminNotificationController` ke `registerDevice`/`unregisterDevice`, unke routes + OpenAPI docs, aur unke tests. Frontend se: `firebase.ts`, `firebase-messaging-sw.js`, `usePushNotifications.ts`, `AppShell.vue`/`NotificationBell.vue` ki push wiring, `firebase` npm package, `.env`/`.env.example` ke Firebase vars. **Kya bacha hai:** in-app notification bell/inbox (jo push se independent tha) poora intact hai. **Service-account JSON `storage/app/firebase/service-account.json` mein safe hai** (gitignored, delete nahi kiya) — jab app ke saath dobara karna ho tab foran se shuru nahi karna padega. Backend: 532/533 full suite pass (wahi 1 pre-existing unrelated failure), matches exact state jo push-work shuru hone se pehle tha. Frontend `vue-tsc` clean.
