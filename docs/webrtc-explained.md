# What Is WebRTC — A Primer

> This is a conceptual explainer, not part of the numbered spec sequence (`00`–`08`) and not
> specific to this codebase. For how *this app* actually uses WebRTC (Reverb signaling, seat
> model, camera renegotiation, known limitations), see
> [`webrtc-voice-chat.md`](webrtc-voice-chat.md).

## 1. What it is

**WebRTC (Web Real-Time Communication)** is a browser API that lets two devices exchange audio,
video, or arbitrary data **directly** — peer-to-peer — instead of relaying it through a server.
Once a WebRTC connection is up, the media travels straight from one browser to the other; no
backend server sits in the middle carrying those bytes.

That's the core value proposition: no third-party media relay to pay for or depend on, at the
cost of doing your own "how do two browsers find each other" plumbing.

## 2. Two separate jobs: signaling vs. media

This is the single most important thing to understand about WebRTC — it deliberately splits
into two unrelated problems:

| Job | What it does | Who solves it |
|---|---|---|
| **Signaling** | Let two browsers discover each other and agree on how to talk (codecs, network paths) *before* any media flows | **Not WebRTC's job.** You bring your own channel — a WebSocket server, a chat app, even copy-pasting text works in principle |
| **Media** | The actual audio/video/data once both sides are ready | WebRTC itself, browser-to-browser, no server involved |

WebRTC does not include a signaling server. It's a deliberate gap in the spec — any transport
you already have (a WebSocket, an HTTP API, a message queue) can carry the handshake messages.

## 3. The handshake: Offer / Answer / ICE

Say browser A wants to call browser B:

1. **Offer** — A creates an SDP (Session Description Protocol) blob: a text document describing
   what it can send/receive (audio/video codecs, resolution, etc.), and sends it to B over
   whatever signaling channel is in use.
2. **Answer** — B looks at A's offer, creates its own matching SDP, and sends it back the same
   way.
3. **ICE candidates** — in parallel, both sides gather a list of "here's an address you might
   be able to reach me at" (ICE = Interactive Connectivity Establishment) and exchange those
   too, since most devices sit behind a home/office router and don't have a plain public IP.

Once both sides have an SDP from the other and enough ICE candidates have been tried, the
browsers pick a working path and the direct connection opens. This whole cycle repeats (called
**renegotiation**) any time something about the call changes mid-stream — e.g. a camera turning
on adds a new video track, which needs a fresh offer/answer to describe it.

## 4. STUN and TURN — getting through NAT

Almost nobody has a plain public IP address anymore; home and office networks sit behind NAT
(Network Address Translation), so two devices can't just dial each other's local IP.

- **STUN server**: a very small, cheap-to-run server whose only job is to tell a device "this is
  what your address looks like from the outside." Both sides ask a STUN server this and share
  the answer as an ICE candidate. Enough for most home/office networks.
- **TURN server**: for the networks STUN can't get through (strict corporate firewalls,
  symmetric NAT), a TURN server actually relays the media — at that point it's no longer true
  peer-to-peer, and you're paying for someone's server bandwidth for every call.

Using only a free public STUN server (no TURN) is a normal, low-cost choice — it works for the
large majority of networks. The tradeoff is that a small number of very restrictive networks
simply won't be able to connect at all, with no automatic fallback.

## 5. Mesh vs. SFU — what happens with more than 2 people

- **Mesh** (what a plain WebRTC setup like this gives you by default): every participant opens
  a direct `RTCPeerConnection` to every other participant. Simple, no extra infrastructure — but
  bandwidth and CPU cost scale with the *number of connections*, which grows quadratically with
  participants (n people → each sending n-1 separate streams).
- **SFU** (Selective Forwarding Unit): everyone sends one stream to a central server, which
  forwards copies to everyone else. Scales much better for large rooms, but now there's a real
  server in the media path — infrastructure to run, and no longer "no third party."

Mesh is the right call for a handful of simultaneous speakers; it stops being practical well
before room sizes get large.

## 6. The pieces, named

| Concept | What it actually is |
|---|---|
| `RTCPeerConnection` | The browser object representing one connection to one peer — handles the offer/answer/ICE dance and carries the media once connected |
| `getUserMedia()` | The API that asks the OS for camera/microphone access and hands back a `MediaStream` |
| SDP (offer/answer) | Plain-text description of what a peer can send/receive |
| ICE candidate | One "try reaching me here" address |
| STUN | A server that tells a device its own public-facing address |
| TURN | A server that relays media when a direct path can't be found |
| Signaling channel | Whatever transport carries the offer/answer/ICE messages — a WebSocket server, in this app's case |

## 7. Why this matters for a "no third party" requirement

Because signaling is explicitly *not* part of WebRTC, "no third-party service" is achievable —
you just need any transport you already control to carry a few short text messages before the
call starts. The actual audio/video never has to touch that transport, or any server you run, at
all. That's what makes an in-house WebRTC setup (self-hosted signaling + WebRTC + free STUN)
a real alternative to a paid RTC platform, provided the room sizes stay small enough for mesh
to hold up.
