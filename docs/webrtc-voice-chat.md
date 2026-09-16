# Room Seat Voice & Video Chat — WebRTC over Reverb

> **Status:** implemented, local/dev-tested. This is an implementation note, not part of the
> numbered spec sequence (`00`–`08`). It intentionally **deviates** from `03-api-contract.md`
> §5 and `04-epic-backlog.md` D.2/D.5, which specify Agora RTC/RTM as the media layer. This
> feature uses in-house peer-to-peer WebRTC instead — no Agora, no third-party SFU/TURN
> service beyond a public STUN server. See "Why not Agora" below before extending this.
>
> Camera is an add-on to the same connections voice already opened — see §4.8. It is **not**
> a separate calling mode; a seat's video, when on, rides the exact same `RTCPeerConnection`
> as its audio.

## 1. The two jobs: signaling vs. media

Real-time voice chat needs two different things, solved two different ways:

| Job | Who does it | How |
|---|---|---|
| **Signaling** — "who's here, who's talking, how do two browsers find each other" | Laravel Reverb (self-hosted WebSocket server, Pusher protocol) | Server-broadcast events (`seat.occupied`, etc.) + browser-to-browser "client events" relayed through the same socket |
| **Media** — the actual audio bytes | WebRTC, browser-to-browser | Direct peer-to-peer connection; once established, audio never touches Reverb or Laravel |

Reverb is the matchmaker. It never carries a single byte of audio — its job ends the moment
two browsers have exchanged enough information to open a direct connection to each other.

## 2. Data model

- `room_seats.user_id` / `occupied_at` — who is sitting where (existing column).
- `room_seats.is_self_muted` (new, this feature) — the seat holder's own mute toggle,
  separate from `is_muted_by_host` (an admin/moderator action, untouched by this feature).
  `RoomSeat::isEffectivelyMuted()` ORs the two.
- `room_seats.is_camera_on` — pre-existing column (docs/02 §3.2), previously unused by any
  service. This feature is the first thing that actually writes to it
  (`RoomParticipationService::setCamera()`).
- No new table for signaling. Offers/answers/ICE candidates are never persisted — they are
  relayed live and thrown away, exactly like the existing `UserTyping` broadcast event.

## 3. Backend pieces

| File | Role |
|---|---|
| `app/Domain/Rooms/RoomParticipationService.php` | The user's own join/leave, seat take/leave, self-mute, self-camera — separate from the admin-only `RoomService`/`RoomModerationService`. |
| `app/Http/Controllers/Api/RoomController.php` | `GET /rooms/{uuid}/state`, `POST .../join`, `POST .../leave`, `POST .../seats/{n}/take`, `POST .../seats/leave`, `PATCH .../mic`, `PATCH .../camera`. Matches the paths in `03-api-contract.md` §4; drops the Agora `rtc` block from the `state` response and adds a `signaling` block instead (channel name + STUN server). |
| `app/Events/Rooms/{SeatOccupied,SeatVacated,MicToggled,CameraToggled}.php` | `ShouldBroadcastNow` events on the `room.{uuid}` **presence** channel. |
| `routes/channels.php` — `room.{uuid}` | Presence-channel authorization: must be an active, non-banned `room_members` row. Presence (not private) because the client needs "who's actually connected right now" for free — that's what a presence channel already tracks via `pusher:member_added`/`member_removed`. |
| `routes/api.php` | Registers the above under the existing `auth:sanctum` mobile group, plus a `local`-only `GET /dev/login-as/{user}` (mirrors `Admin\DevHelperController`'s pattern) so the demo seat/voice flow can be exercised without building OTP/SMS delivery first. |
| `.env` `BROADCAST_CONNECTION=reverb` | Was `log` by default (see `config/broadcasting.php`'s own comment on why) — has to be `reverb` for any environment where a client actually needs to receive events. |

## 4. Frontend: `resources/views/rooms/show.blade.php`

Self-contained page (Tailwind CDN + pusher-js CDN + vanilla JS, no build step) at
`GET /rooms/{uuid}/preview`. All the logic below lives in one `<script>` block.

### 4.1 Connecting

```
pusher-js  →  presence-room.{uuid}   (auth via Authorization: Bearer <token> to /broadcasting/auth)
```

`wsHost`/`wsPort`/TLS default to whatever host the browser used to load the page (not the
server's own `.env` value) — a phone on the LAN loading the page via a LAN IP has to reach
Reverb at that *same* LAN IP. `?ws_host=&ws_port=&ws_tls=` query params override this for a
tunnelled setup (ngrok etc.) where the app and Reverb end up on two different public hosts.

### 4.2 Taking a seat

1. `getUserMedia({audio: true})` → local `MediaStream`.
2. `POST /seats/{n}/take` → server marks the seat occupied, broadcasts `seat.occupied`.
3. Every other connected client receives that event.

### 4.3 Who calls whom (avoiding a race)

Only a **seated speaker** ever originates a call (creates the SDP offer). A plain listener
never initiates — it only ever answers. This one rule is enough to get every speaker
connected to every listener with no coordination needed.

For two speakers (both have audio to send each other), calling first would otherwise be a
race — both might send an offer at the same instant. `ensureConnectionTo()` breaks the tie by
comparing the two users' uuids: only the lexicographically smaller one calls. The other side
just waits for that offer.

```js
function ensureConnectionTo(remoteUuid) {
    if (!state.mySeat || !state.localStream || remoteUuid === state.myUuid) return;
    if (isSpeakerUuid(remoteUuid) && state.myUuid > remoteUuid) return; // let them call me
    callPeer(remoteUuid);
}
```

This is called from three places: `pusher:member_added` (a new listener joins), `seat.occupied`
(someone becomes a speaker), and once for every existing room member right after *this* client
becomes a speaker (`maybeCallEveryone()`).

### 4.4 The actual handshake

```
A (existing speaker)                    B (just sat down)
──────────────────────                  ──────────────────
new RTCPeerConnection()
addTrack(my mic)
createOffer() / setLocalDescription()
──── client-signal {type:'offer'} ────▶
                                         new RTCPeerConnection()
                                         addTrack(my mic)   // if B is also seated
                                         setRemoteDescription(offer)
                                         createAnswer() / setLocalDescription()
◀──── client-signal {type:'answer'} ────
setRemoteDescription(answer)

(both sides, whenever ICE finds a candidate)
──── client-signal {type:'ice'} ───────▶
◀──── client-signal {type:'ice'} ───────
```

`client-signal` is a **Pusher/Reverb client event** — triggered directly from one browser and
relayed to the other by Reverb without an HTTP round-trip through Laravel at all
(`state.channel.trigger('client-signal', {...})`). The payload carries `to`/`from` (uuids) so
every client can ignore signals not addressed to it, since presence channels broadcast client
events to everyone subscribed.

ICE candidates are discovered using a single public STUN server
(`stun:stun.l.google.com:19302`, hardcoded in `CONFIG.iceServers`) — it only tells a browser
its own reachable address; no media or signaling data passes through it. No TURN server is
configured, so two clients both behind restrictive/symmetric NAT may fail to connect directly
(see "Known limitations").

### 4.5 Listeners hear, but never speak

A plain listener's `RTCPeerConnection` is created with `state.localStream` unset, so
`createPeer()` never calls `addTrack()` — the connection is receive-only from that side. No
special-case code is needed for this; it falls out of "only speakers ever have a stream to
attach."

### 4.6 Leaving a seat vs. leaving the room

Vacating a seat (`leaveSeatUi()`) stops the local mic tracks but **does not** close existing
peer connections — the user is still in the room and should keep hearing whoever remains
seated. Connections are only torn down when the person actually disconnects
(`pusher:member_removed`) or when a stale listener-mode connection needs rebuilding as
bidirectional (`seat.occupied` closes-then-recalls in that one case).

### 4.7 Mute

`is_self_muted` is a persisted column so late joiners see correct state via `GET /state`.
Client-side, muting just sets `track.enabled = false` (no renegotiation needed — the
connection stays open, the remote side simply stops receiving audio) and calls `PATCH /mic` so
everyone else's UI updates via `mic.toggled`.

### 4.8 Video (camera toggle)

Off by default when a seat is taken — a speaker turns it on separately, same pattern as the
mic. Unlike mute, adding or removing a track **does** require renegotiation (a fresh
offer/answer), because the set of tracks a connection carries is part of its SDP. That's
handled generically rather than as a one-off:

```js
pc.onnegotiationneeded = function () {
    if (pc.signalingState !== 'stable') return; // mid-handshake already, see below
    pc.createOffer().then(...).then(() => sendSignal(remoteUuid, 'offer', pc.localDescription));
};
```

This one handler covers **both** the very first offer (created in §4.4 — `addTrack()` during
`createPeer()` queues it) and every later renegotiation. Turning the camera on calls
`pc.addTrack(videoTrack, localStream)` on every existing connection; turning it off calls
`pc.removeTrack(sender)`. Either one fires `onnegotiationneeded` on that connection, which
sends a fresh offer covering whatever tracks are now attached — no separate "camera" signaling
path exists.

**The `signalingState !== 'stable'` guard matters here specifically**: when client B answers an
incoming offer, `createPeer()` adds B's own tracks *before* `handleSignal()` calls
`setRemoteDescription()` on the same tick — that add would otherwise queue a spurious
`negotiationneeded` for a connection that's about to be driven by the incoming offer instead.
By the time that queued event actually runs (it's a task, so it waits for the current
microtask queue — including the `setRemoteDescription()` promise — to drain first),
`signalingState` has already moved off `'stable'` and the guard skips it. What this guard does
**not** cover: two already-connected peers who happen to renegotiate (e.g. both flip their
camera) in the same round-trip — that's a real offer collision, unhandled (see limitations).

**Rendering:** a video track's stream replaces that seat's avatar `<img>` with a `<video>`
(`attachRemoteMedia()`); since one `MediaStream` carries both tracks, the same `<video>` plays
the audio too and the separate `<audio>` sink is torn down. My own camera gets a **muted**
local `<video>` preview in my own seat (`showLocalVideoPreview()`) — muted specifically so I
never hear my own mic looped back through my own preview element. Camera off, or `ontrack`'s
`track.onended` firing for a remote peer's video, reverts that seat to the plain avatar via
`revertSeatAvatarDisplay()`, which reads the seat's current locked/muted state off the DOM
first so it doesn't clobber either while restoring the avatar.

## 5. Testing this locally

The simplest path, no LAN/tunnel setup needed: `getUserMedia` only works in a "secure
context" — `https:`, or `localhost`/`127.0.0.1`. Two plain browser tabs against
`http://127.0.0.1:8000/rooms/preview`, each picking a different demo user from the
`local`-only dev-login overlay, exercise the entire flow (mic permission, seat take, WebRTC
connect, mute) with zero extra infrastructure. `sessionStorage`-scoped tokens keep the two
tabs' sessions independent.

Testing from a second physical device on the same network needs either:
- the LAN IP + `chrome://flags/#unsafely-treat-insecure-origin-as-secure` (per test device), or
- an HTTPS tunnel (ngrok) — in which case Reverb needs **its own** tunnel too (a second
  `ngrok http 8080`, or both tunnels from one `ngrok start --all` config, since the free plan
  only allows one *agent session*, not one tunnel), and the page needs
  `?ws_host=<reverb-tunnel-host>&ws_port=443&ws_tls=1` so the browser's WebSocket target isn't
  assumed to be "same host as the page, port 8080" (true on a LAN, false through two separate
  tunnels).

A built-in on-page debug panel (`#gf-debug`) logs every step — socket connect, presence
join/leave, seat events, offer/answer/ICE state, and whether an audio track actually arrived —
so a failure is visible without opening devtools, including from a phone.

## 6. Known limitations

- **Mesh, not SFU.** Every speaker opens a direct connection to every other participant.
  Fine for the handful of occupied seats a room actually has; does not scale to large
  listener counts the way Agora's SFU would (each speaker's upload bandwidth grows with
  listener count). This is the direct tradeoff of choosing "no third-party media service."
- **STUN only, no TURN.** Two peers both behind symmetric/restrictive NAT may never find a
  direct path. Acceptable for same-LAN or typical home-NAT testing; a production rollout
  behind arbitrary corporate/mobile networks would need a TURN relay (self-hosted `coturn` or
  a paid one) to guarantee connectivity.
- **No reconnection renegotiation beyond what's implemented.** A page refresh while seated
  re-acquires the mic and re-calls every currently-known speaker (`applySnapshot`'s reconnect
  block) — this is best-effort, not a fully robust ICE-restart/renegotiation implementation. A
  refresh also does not re-enable the camera even if it was on before — the user has to tap it
  again after reload.
- **Simultaneous renegotiation isn't glare-safe.** The `onnegotiationneeded` guard (§4.8)
  handles the initial connect correctly, but if two already-connected peers both toggle their
  camera in the same round-trip, both can send an offer at once with nothing to resolve the
  collision (no "polite peer" rollback implemented). Rare in practice; would show up as one
  side's video failing to appear until they toggle again.
- **Text chat, gifting, hand-raise, co-host** — all out of scope for this feature; the existing
  admin-side seat lock/mute/kick endpoints are untouched.

## 7. Why not Agora (the documented plan)

The user explicitly asked for peer-to-peer WebRTC with **no third-party service**, after being
shown that `03-api-contract.md`/`04-epic-backlog.md` specify Agora. This is a deliberate,
confirmed deviation for this feature only — not a signal to migrate the rest of the calling
surface (`/calls`, 1:1/group voice+video, `calls` table in `02-database-schema.md` §192) away
from Agora. That surface is unbuilt and untouched by this work.
