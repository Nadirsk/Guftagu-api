# Real-Time Communication Platform — Architecture & Phase-wise Plan

> A voice/video communication platform in the style of Agora SD-RTN, built on WebRTC.
> Status: **PLAN, awaiting approval.** Nothing in this document has been implemented yet.
> Last updated: 2026-09-30

---

## Table of Contents

1. [Goal](#1-goal)
2. [How Agora SD-RTN works](#2-how-agora-sd-rtn-works)
3. [Mapping SD-RTN concepts to WebRTC](#3-mapping-sd-rtn-concepts-to-webrtc)
4. [Target architecture](#4-target-architecture)
5. [End-to-end call flow](#5-end-to-end-call-flow)
6. [Technology stack](#6-technology-stack)
7. [Assumptions and open decisions](#7-assumptions-and-open-decisions)
8. [Phase-wise plan](#8-phase-wise-plan)
9. [Quality targets (KPIs)](#9-quality-targets-kpis)
10. [Team](#10-team)
11. [Infrastructure and cost basics](#11-infrastructure-and-cost-basics)
12. [Key risks](#12-key-risks)
13. [Sources](#13-sources)

---

## 1. Goal

Build our own real-time voice/video network and developer platform, similar in concept to Agora's
Software-Defined Real-Time Network (SD-RTN), using **WebRTC** as the media technology.

- **First customer:** Guftagu (voice rooms in the mobile app).
- **Long-term:** a platform other apps can integrate through our SDKs, like Agora or ZEGOCLOUD.
- **Realistic first-year target:** an SD-RTN-style network in **3–5 regions**, strongest in
  Pakistan, the Gulf, and South Asia. Agora's global network took ~10 years and hundreds of
  engineers; the core ideas are the same, but the scale is not.

---

## 2. How Agora SD-RTN works

The public internet is **best-effort**: ISPs route packets along the *cheapest* path (their own
network → free peers → paid transit), not the fastest. This causes packet loss, jitter, and delay
that hurt live calls. SD-RTN is a **software overlay network** of Agora's own data centres on top of
the internet, where Agora controls the routing.

| # | Concept | What it does | Agora's numbers |
|---|---|---|---|
| 1 | **Access points (not DNS)** | For every join request, the backend builds a custom list of the best entry servers for that client, based on live network conditions. DNS-based CDNs are slow to react to outages. | Connection in 1–2 s, first-time connection success |
| 2 | **Edge data centres** | Servers close to users keep the "last mile" short | 200+ data centres |
| 3 | **Private backbone overlay** | After the edge, media travels server-to-server on routes Agora chooses | Median global latency < 76 ms |
| 4 | **Smart routing + redundancy** | Paths are monitored continuously; by default data is sent over the **3 best paths** simultaneously | ≤ 0.5% packet loss per minute |
| 5 | **Large channel** | CDN-like tree for huge audiences: source → continental node → local data centre → audience, with shared paths | 1M+ audience in one channel |
| 6 | **Seamless failover** | If a server fails, everyone in the session is moved to another server with no noticeable interruption | 99.99% availability |
| 7 | **Full monitoring** | "Every user, every stream, every connection point" | Online/offline reports, real-time incident alerts |
| 8 | **Standard connection points** | Accepts and outputs WebRTC, RTP, RTMP, HLS, OBS | — |

**Conclusion:** SD-RTN is essentially **SFU media servers in many locations, linked into one network
(cascading), plus a smart dispatcher and router.** Every part of that can be built with WebRTC.

---

## 3. Mapping SD-RTN concepts to WebRTC

| SD-RTN component | Our WebRTC-based equivalent |
|---|---|
| Access point | **Allocator service**: an API returning a ranked list of edge nodes (GeoIP + node load + probe data). The SDK probes the top 2–3 and connects to the fastest. |
| Edge node | **SFU node** (mediasoup) with **TURN** (coturn) on the same host, including a TCP/TLS 443 fallback |
| Backbone | **SFU-to-SFU cascading** using mediasoup `PipeTransport` (RTP relay between servers) |
| Smart routing | **Probe mesh + route controller**: every node pings every other node (RTT/loss/jitter); the controller computes best and backup paths |
| Redundant paths | **Multipath relay**: primary and backup path; the receiving node de-duplicates by RTP sequence number |
| Packet-loss resilience | WebRTC tools: **Opus in-band FEC, RED (RFC 2198), NACK/RTX, DTX, jitter buffer**; video: **simulcast/SVC, TWCC/GCC bandwidth estimation, PLI/FIR** |
| Large channel | **Distribution tree**: publisher's edge → hub relay → many edges → subscribe-only audience |
| Failover | Node heartbeats + room registry in Redis; allocator reassigns; SDK auto-reconnects (ICE restart / rejoin) |
| Monitoring | SDK uploads `getStats()` data; nodes export metrics → ClickHouse + Grafana; per-call **Call Inspector** |
| RTMP / HLS / SIP | **Gateway nodes** (GStreamer/FFmpeg) and recording workers |

### Why mediasoup (instead of LiveKit)

- Cascading one room across servers and regions is the core of SD-RTN. **Open-source LiveKit does not
  do this** (a room lives on one node; cross-node/region mesh is a LiveKit Cloud feature).
- mediasoup has built-in **`PipeTransport`** for server-to-server relay, the main building block for cascading.
- The core is C++ (fast) and it is controlled from Node.js, so the routing and orchestration logic is ours.
- `mediasoup-client` works in browsers and in React Native through `react-native-webrtc`.
- Alternative: **Pion (Go)**. It gives even more control, but we would write much more of the SFU ourselves.

---

## 4. Target architecture

```
                         ┌───────────────────────────────────────┐
                         │   CUSTOMER APP (e.g. Guftagu)          │
                         │   Our SDK: Web │ React Native │ later: │
                         │   Flutter, Android, iOS                │
                         │   (WebRTC + auto-reconnect + probing + │
                         │    stats upload + simple join() API)   │
                         └──────┬───────────────────┬─────────────┘
              ① join request    │                   │ ③ WebRTC media (UDP; TCP/TLS 443 fallback)
              (token, channel)  ▼                   ▼
┌──────────────────────────────────┐   ┌──────────────── EDGE LAYER (per region) ─────────────────┐
│ ACCESS / ALLOCATOR SERVICE       │   │  Region: Dubai        Region: Mumbai     Region: Frankfurt│
│ • validates token                │②  │  ┌───────────────┐   ┌──────────────┐   ┌──────────────┐ │
│ • GeoIP + node load + probe data │──►│  │Edge node      │   │Edge node     │   │Edge node     │ │
│ • returns ranked edge list       │   │  │ signaling(WS) │   │              │   │              │ │
│ • room placement                 │   │  │ mediasoup SFU │   │  ...         │   │  ...         │ │
└──────────────┬───────────────────┘   │  │ coturn TURN   │   │              │   │              │ │
               │                       │  └──────┬────────┘   └──────┬───────┘   └──────┬───────┘ │
               ▼                       └─────────┼───────────────────┼──────────────────┼─────────┘
┌──────────────────────────────────┐             │ ④ cascading (PipeTransport RTP relay)│
│ ORCHESTRATION                     │            ▼                   ▼                  ▼
│ • Room registry (Redis):          │   ┌──────────────── BACKBONE LAYER ─────────────────────────┐
│   which room lives on which nodes │   │  Hub relay nodes (e.g. Dubai hub, Singapore hub, EU hub)│
│ • Node manager: register,         │◄──│  • Probe mesh: every node pings every node (RTT/loss)   │
│   heartbeat, drain, failover      │   │  • Route controller: best path + backup path            │
│ • Route controller                │   │  • Large channel: publisher→hub→edges→audience tree     │
└──────────────┬───────────────────┘   └─────────────────────────────────────────────────────────┘
               │ events (join / leave / minutes / quality)
               ▼
┌──────────────────────────── PLATFORM / BUSINESS LAYER ──────────────────────────────┐
│ Accounts & Projects (App ID + App Certificate) │ Token service │ REST API (kick,    │
│ mute, list channels, recording) │ Webhooks │ Usage metering │ Billing │ Console UI  │
└──────────────────────────────────────────┬──────────────────────────────────────────┘
                                           ▼
┌── GATEWAYS ────────────────────┐ ┌── DATA & OBSERVABILITY ────────────────────────────┐
│ Recording → S3/MinIO           │ │ NATS/Kafka → ClickHouse (stats, usage)             │
│ RTMP/HLS out (YouTube, CDN)    │ │ Prometheus + Grafana + Loki │ Call Inspector      │
│ RTMP/OBS in │ SIP (later)      │ │ Alerts (node down, loss spike, region degraded)    │
└────────────────────────────────┘ └────────────────────────────────────────────────────┘
```

### Layers

| Layer | Responsibility |
|---|---|
| **Client SDK** | WebRTC capture/playback, join/leave, roles (host/audience), mute, active-speaker and network-quality callbacks, edge probing, auto-reconnect, stats upload |
| **Access / Allocator** | Token validation, choosing edge nodes per client, room placement |
| **Edge layer** | Signaling (WebSocket), SFU (mediasoup workers), TURN; terminates client WebRTC connections |
| **Backbone layer** | Relaying streams between edges and hubs, probe mesh, best-path and backup-path routing, broadcast trees |
| **Orchestration** | Room registry, node lifecycle (register, heartbeat, drain, failover), route computation |
| **Platform / Business** | Projects, App ID/Certificate, tokens, REST API, webhooks, metering, billing, developer console |
| **Gateways** | Recording, RTMP/HLS out, RTMP/OBS in, SIP (later) |
| **Data & Observability** | Metrics, logs, call-quality analytics, alerting |

---

## 5. End-to-end call flow

Example: a Guftagu user joins voice room `room_123`.

1. **Token:** the Guftagu backend signs a short-lived token with its App Certificate using our server SDK.
2. **Join:** the app calls `sdk.join(appId, "room_123", token, uid, role)`; the SDK calls the **Allocator**.
3. **Allocation:** the allocator validates the token and checks the **Room Registry** for where `room_123`
   already lives. It returns the 2–3 best edge nodes for this client (e.g. `dubai-edge-2`).
4. **Connect:** the SDK probes the candidates, opens a WebSocket to the best one, and negotiates WebRTC.
   Target: **< 2 s** join time.
5. **Media:** a speaker publishes audio **once**. Listeners on the same node receive it directly. If
   listeners are on other nodes or regions, the stream is **piped** across the backbone (optionally over
   a backup path as well).
6. **Telemetry:** the SDK uploads quality stats every few seconds. The edge emits join/leave events,
   which the platform turns into **minutes → metering → billing → webhook to Guftagu**.
7. **Failure:** if a node dies, heartbeats stop, the orchestrator re-places the room, and the SDK
   reconnects to the next node in its list.

---

## 6. Technology stack

| Area | Choice |
|---|---|
| Media engine (SFU) | **mediasoup** (C++ core, Node.js control) |
| Signaling / Allocator / Orchestrator | Node.js + TypeScript (Go possible later for the allocator) |
| TURN | coturn |
| Room registry / state | Redis (clustered) |
| Event bus | NATS (or Kafka) |
| Stats / usage store | ClickHouse |
| Platform DB | PostgreSQL or MySQL |
| Platform backend + console | Laravel (team knows it; it carries no media) + Next.js console |
| Client SDK | TypeScript core; `mediasoup-client` (web); `react-native-webrtc` (React Native, needs an Expo dev build) |
| Gateways | GStreamer / FFmpeg |
| Infrastructure | High-bandwidth bare metal / VMs for media nodes (e.g. Hetzner, OVH, local PK data centres); Docker; Terraform + Ansible |
| Monitoring | Prometheus, Grafana, Loki |
| Weak-network testing | Linux `tc netem` (simulated loss / jitter / delay) |

### Proposed repository layout (monorepo)

```
rtc-platform/
├── media-node/        # mediasoup SFU + signaling (WebSocket) + pipe/cascade logic
├── allocator/         # access-point service: token check, edge selection, room placement
├── orchestrator/      # room registry, node manager, probe collector, route controller
├── sdk-core/          # shared TypeScript SDK logic (signaling protocol, reconnect, stats)
├── sdk-web/           # browser SDK (mediasoup-client)
├── sdk-react-native/  # React Native SDK (react-native-webrtc)
├── server-sdks/       # token builders: PHP, Node, Python
├── platform/          # Laravel: projects, keys, REST API, webhooks, metering, billing
├── console/           # Next.js developer console
├── gateways/          # recording, RTMP/HLS egress, RTMP ingress
├── infra/             # Terraform, Ansible, Docker, Grafana dashboards
└── tools/             # load tester, netem profiles, probe tools
```

---

## 7. Assumptions and open decisions

This plan assumes the following. **Each item must be confirmed before implementation starts.**

| # | Decision | Assumed in this plan | Alternatives |
|---|---|---|---|
| D1 | Scope | Guftagu is the first customer; platform layer built in parallel so we can sell later | Guftagu-only (smaller P4) |
| D2 | Media engine | mediasoup | Pion (Go), LiveKit (no OSS cascading) |
| D3 | v1 features | Voice rooms (host/speakers/audience) + 1-to-1 voice calls | Add video in v1 |
| D4 | First regions | Dubai/Bahrain + a Pakistan data centre if available | Mumbai, Singapore, Frankfurt |
| D5 | Hosting provider | To be decided | Hetzner / OVH / local PK DC / AWS (expensive egress) |
| D6 | Code location | New separate repo `rtc-platform/`, outside the Guftagu app code | Inside Guftagu repo |
| D7 | Platform layer stack | Laravel + Next.js | Node.js / Go |

---

## 8. Phase-wise plan

Overview (durations are rough estimates for the team in section 10):

| Phase | Name | Goal | Duration |
|---|---|---|---|
| **P0** | Proof of Concept | Prove WebRTC voice quality from Pakistan on our own server | 2–3 weeks |
| **P1** | Single-Region Network | Production-grade network in one region, SDK v1, Guftagu integrated | 6–8 weeks |
| **P2** | Multi-Region SD-RTN | Our own backbone across 2–3 regions with smart routing and failover | 8–12 weeks |
| **P3** | Quality Engineering | Good calls on weak/mobile networks (runs alongside P1 onward) | Ongoing |
| **P4** | Platform Layer | Developer console, keys, REST, webhooks, metering, billing | 6–8 weeks (parallel with P1/P2) |
| **P5** | Gateways + Large Channel | Recording, RTMP/HLS, OBS input, broadcast to 10k+ listeners | 6–8 weeks |
| **P6** | Advanced | AI routing, noise suppression, transcription, moderation, SIP, video expansion | Later |

```
Month:        1     2     3     4     5     6     7     8     9
P0 PoC       ███
P1 Region         ██████████
P2 Multi-reg                ██████████████
P3 Quality        ═══════════════════════════════════════════ (ongoing)
P4 Platform         ██████████████
P5 Gateways                               ██████████
P6 Advanced                                         ████ →
```

---

### P0 — Proof of Concept (2–3 weeks)

**Goal:** confirm that self-hosted WebRTC gives good voice quality for our users before investing further.

**Scope**
- One media server in the nearest region (Dubai, or a Pakistan DC).
- Minimal signaling and one voice room, with no production concerns yet.

**Tasks**
- [ ] Provision one server (public IP, UDP port range open, TLS certificate)
- [ ] Install and configure mediasoup (one worker per CPU core)
- [ ] Minimal WebSocket signaling: join room, create transports, produce/consume audio
- [ ] Install coturn (UDP 3478 + TLS 443)
- [ ] Web test page (browser) for a voice room
- [ ] React Native test screen in Guftagu (Expo dev build + `react-native-webrtc` + `mediasoup-client`)
- [ ] Basic load test: 1 speaker → 50 / 100 / 200 listeners on one server
- [ ] Measure latency, packet loss, jitter, CPU, and bandwidth from real devices in Karachi, Lahore, and Islamabad on Wi-Fi and 4G

**Deliverables**
- Working PoC voice room (web + React Native)
- Measurement report: latency, loss, CPU per listener, bandwidth per listener

**Exit criteria**
- Mouth-to-ear latency **< 300 ms** for PK users on 4G
- Audio remains understandable at **15–20% simulated packet loss**
- Join time **< 3 s**
- **200+ listeners** on a single server without audio degradation

**Risks:** Pakistani mobile networks may block or throttle UDP (mitigation: TURN over TLS 443). Expo dev build setup effort.

---

### P1 — Single-Region Network (6–8 weeks)

**Goal:** a production-grade network in one region, with SDK v1, integrated into Guftagu voice rooms.

**Scope**

1. **Media node** (`media-node/`)
   - [ ] Production signaling protocol (WebSocket, JSON messages, versioned)
   - [ ] Roles: host / speaker / audience (audience subscribe-only)
   - [ ] Server-side mute, kick, and max-speaker limits
   - [ ] Active-speaker detection (audio level observer) → events to clients
   - [ ] **Intra-region cascading:** a room spans multiple nodes via `PipeTransport` when one node is full
   - [ ] Node metrics export (CPU, bandwidth, rooms, participants)
2. **Allocator** (`allocator/`)
   - [ ] `POST /v1/allocate` → validates token, returns ranked edge list + edge session ticket
   - [ ] Room placement: new room → least-loaded node; existing room → same node or cascade to a new node
3. **Orchestrator** (`orchestrator/`)
   - [ ] Node registration and heartbeat (every 2–5 s)
   - [ ] Room registry in Redis (`room → nodes`, `node → load`)
   - [ ] Node drain (for deploys) and failure detection → re-placement
4. **Tokens** (`server-sdks/`)
   - [ ] Token format: JWT (HS256) signed with the App Certificate; claims: `appId`, `channel`, `uid`, `role`, `privileges`, `exp`
   - [ ] Token builders: **PHP** (Guftagu Laravel backend) and Node
5. **SDK v1** (`sdk-core/`, `sdk-web/`, `sdk-react-native/`)
   - [ ] API: `init(appId)`, `join(channel, token, uid, role)`, `leave()`, `muteLocalAudio()`, `setRole()`
   - [ ] Events: `userJoined`, `userLeft`, `activeSpeaker`, `networkQuality`, `connectionStateChanged`, `tokenWillExpire`
   - [ ] Edge probing (pick the fastest of the allocator's candidates)
   - [ ] **Auto-reconnect** (ICE restart, then full rejoin on another node)
   - [ ] Stats upload every 5–10 s (RTT, loss, jitter, bitrate)
6. **Guftagu integration**
   - [ ] Laravel endpoint to issue room tokens
   - [ ] Replace or connect the voice-room screen to SDK v1
7. **Ops**
   - [ ] Docker images, Ansible provisioning, Grafana dashboards, alerts (node down, high loss)

**Deliverables:** one production region; SDK v1 (web + React Native); PHP token builder; Guftagu voice rooms running on our network.

**Exit criteria**
- **1,000 concurrent listeners** in one room spread across **3+ nodes** in one region
- Node killed during a call → users reconnected in **< 3 s**
- Join success rate **≥ 99.5%**, median join time **< 2 s**
- Zero-downtime deploy using node drain

**Risks:** cascading bugs (state sync between nodes); reconnect edge cases on mobile (app backgrounding, network switching between Wi-Fi and 4G).

---

### P2 — Multi-Region SD-RTN (8–12 weeks)

**Goal:** our own backbone. Users connect to their nearest region, and rooms span regions transparently.

**Scope**
- [ ] Add 2 more regions (e.g. Mumbai + Frankfurt or Singapore, per user distribution)
- [ ] **Hub relay nodes** per region for inter-region traffic
- [ ] **Inter-region cascading:** streams piped edge → hub → hub → edge
- [ ] **Probe mesh:** every node measures RTT / loss / jitter to all hubs and peers every few seconds; results go to the orchestrator
- [ ] **Route controller:** builds a network cost graph and computes the best path + backup path (shortest path on measured cost)
- [ ] **Multipath redundancy** (configurable per project/room): send over primary + backup path; de-duplicate at the receiving node
- [ ] **Allocator v2:** GeoIP + live node load + probe data + client-side probe results
- [ ] **Regional failover:** if a region degrades or fails, rooms and users move to the next-best region
- [ ] Global room registry (Redis cluster, or a replicated store across regions)

**Deliverables:** 3-region network with smart routing, redundancy, and failover.

**Exit criteria**
- Cross-region room (PK ↔ EU) end-to-end latency **< 250 ms** median
- Backbone packet loss **≤ 0.5% per minute** with multipath enabled
- Whole-region outage (simulated) → users recovered in **< 5 s**
- Route controller reroutes around a degraded link **within 10 s**

**Risks:** inter-region bandwidth costs; clock and state consistency across regions; complexity of route computation.

---

### P3 — Quality Engineering (ongoing, from P1 onward)

**Goal:** calls sound good on weak and mobile networks. This is what separates a good RTC platform from a basic one.

**Scope**
- [ ] **Audio:** tune Opus (bitrate, in-band FEC, DTX), enable **RED** redundant audio, tune NACK/RTX and the jitter buffer
- [ ] **Video (when enabled):** simulcast / SVC, TWCC bandwidth estimation, keyframe request handling
- [ ] **Network test lab:** `tc netem` profiles (2G/3G/4G, 10/20/40% loss, 100–500 ms jitter, bursts)
- [ ] Automated quality regression tests on each release (MOS-style scoring)
- [ ] **Call Inspector:** per-call timeline of each user's RTT, loss, jitter, bitrate, and reconnects (from SDK stats + node metrics)
- [ ] Quality alerts per region and ISP (e.g. "Jazz 4G loss spike in Lahore")

**Exit criteria**
- Audio understandable at **30% random packet loss**
- Call Inspector available for every call
- Automated weak-network test suite in CI

---

### P4 — Platform Layer (6–8 weeks, parallel with P1/P2)

**Goal:** the "Agora Console" side, so other developers (and Guftagu) can self-serve.

**Scope**
- [ ] Accounts, organisations, team members
- [ ] Projects → **App ID + App Certificate** (rotate / disable)
- [ ] **REST API:** list channels, list users in a channel, kick user, ban, mute, end channel
- [ ] **Webhooks:** channel created/destroyed, user joined/left, recording ready (signed, with retries)
- [ ] **Usage metering:** participant-minutes per project, split by type (audio / SD / HD video) from node events → ClickHouse
- [ ] **Billing:** free tier (e.g. 10k minutes/month), prepaid wallet or monthly invoice, usage limits and alerts
- [ ] **Developer console (Next.js):** projects, keys, usage graphs, Call Inspector access, billing, docs
- [ ] Public documentation + sample apps

**Exit criteria**
- A new developer can sign up, create a project, get keys, and run a sample voice room in **< 15 minutes**
- Metered minutes match raw node events within **±1%**

---

### P5 — Gateways + Large Channel (6–8 weeks)

**Goal:** broadcasting and media integration.

**Scope**
- [ ] **Cloud recording:** mixed audio (and video later) → S3/MinIO; REST start/stop; webhook when ready
- [ ] **RTMP/HLS out:** push a room to YouTube, Facebook, or a CDN
- [ ] **RTMP/OBS in:** bring an external stream into a room
- [ ] **Large channel:** broadcast tree (publisher edge → hubs → many edges → audience) for 10k+ listeners in one room
- [ ] Audience-only fast join path (no publish transport → lighter and faster)

**Exit criteria**
- **10,000 concurrent listeners** in one room, latency < 400 ms
- Recording available within 60 s of room end

---

### P6 — Advanced (later)

- AI/ML-assisted routing (predicting link quality from history)
- AI noise suppression (e.g. RNNoise-based), echo improvements
- Real-time transcription / translation
- Real-time audio content moderation
- SIP / PSTN gateway (phone dial-in)
- Full video product (1-to-1 video, group video, video live streaming)
- Native Android/iOS and Flutter SDKs
- More regions (Singapore, US, Africa, etc.)

---

## 9. Quality targets (KPIs)

| Metric | P1 target | P2+ target | Agora reference |
|---|---|---|---|
| Join time (median) | < 2 s | < 1.5 s | 1–2 s |
| Join success rate | ≥ 99.5% | ≥ 99.9% | "guaranteed first-time" |
| In-region latency (median) | < 150 ms | < 100 ms | < 76 ms global median |
| Cross-region latency (median) | — | < 250 ms | < 400 ms guaranteed |
| Backbone packet loss | — | ≤ 0.5% / min | ≤ 0.5% / min |
| Audio usable at packet loss | 20% | 30% | — |
| Recovery after node failure | < 3 s | < 2 s | "no perceptible interruption" |
| Availability | 99.5% | 99.9% | 99.99% |

---

## 10. Team

| Role | Count | Focus |
|---|---|---|
| WebRTC / media engineer | 1–2 | mediasoup, cascading, routing, quality tuning |
| Backend engineer | 1 | Allocator, orchestrator, platform layer, metering, billing |
| Mobile / SDK engineer | 1 | Web + React Native SDKs, reconnect logic, Guftagu integration |
| DevOps / SRE | 1 | Servers, regions, deploys, monitoring, load testing |

---

## 11. Infrastructure and cost basics

- **Bandwidth is the main cost.** Media servers send far more data than they receive.
  - One Opus audio stream ≈ 40 kbps → 1,000 listener-minutes ≈ **0.3 GB**.
  - Example voice room: 8 speakers × 500 listeners → each listener receives ~320 kbps → **~160 Mbps** egress for the room.
  - 720p video ≈ 1.5 Mbps per stream → 1,000 minutes ≈ **11 GB**.
- **Hosting:** AWS/GCP egress is ~$0.09/GB. High-bandwidth bare metal (Hetzner, OVH, local data centres)
  is typically 5–10× cheaper for media nodes. The control plane can run anywhere.
- **Market pricing for comparison:** Agora ~$0.99 / 1,000 audio minutes, $3.99 / 1,000 SD video minutes,
  $8.99 / 1,000 HD video minutes; ZEGOCLOUD ~$0.99 audio and $3.99 video per 1,000 minutes.
- **Media node requirements:** public IP, open UDP port range (e.g. 40000–49999), TCP/TLS 443 for TURN
  fallback, host networking (no double NAT), good CPU (one mediasoup worker per core).
- **Starting regions:** Dubai/Bahrain (close to PK and the Gulf) + a Pakistan data centre if available;
  then Mumbai, Frankfurt, Singapore.

---

## 12. Key risks

| Risk | Impact | Mitigation |
|---|---|---|
| UDP blocked or throttled on some PK networks | Calls fail or sound bad | TURN over TLS 443; measure per ISP in P0 |
| Cascading complexity | Bugs, audio loss between nodes | Build intra-region cascading first (P1) before cross-region (P2); strong integration tests |
| Mobile reconnect edge cases | Dropped calls on network switch / backgrounding | Dedicated reconnect state machine in SDK; device test matrix |
| Bandwidth cost growth | Margins shrink | Bandwidth-friendly providers; audience-only optimisations; DTX (silence = no data) |
| Small team vs a huge scope | Slow delivery | Strict phase gates; P0/P1 focus on voice only; video later |
| Single point of failure in the control plane | Nobody can join | Allocator/orchestrator run as 2+ instances; Redis cluster; running calls continue if the control plane is briefly down |

---

## 13. Sources

- [Agora — Software-Defined Real-Time Network](https://www.agora.io/en/software-defined-real-time-network/)
- [Agora white paper — SD-RTN delivers Real-Time Internet advantages over CDN](https://hello.agora.io/rs/096-LBH-766/images/Agora_WP_SD-RTN-Delivers-RealTime-Internet-Advantages.pdf)
- [Agora — Network white paper page](https://www.agora.io/en/network-white-paper)
- [Agora SDK 2026 pricing (Forasoft)](https://www.forasoft.com/blog/article/video-call-app-agora-sdk-2026)
- [ZEGOCLOUD pricing (Capterra)](https://www.capterra.com/p/265363/ZEGOCLOUD/)
- [SFU comparison: mediasoup / Janus / LiveKit / Jitsi / Pion (Forasoft)](https://www.forasoft.com/learn/video-streaming/articles-streaming/sfu-comparison-mediasoup-janus-livekit-jitsi-pion)
- [LiveKit — Intro docs](https://docs.livekit.io/home/get-started/intro-to-livekit/)
