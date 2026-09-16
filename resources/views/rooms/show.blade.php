<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $room->name ?? 'Mehfil' }} — Room</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-black">

@php
    $hostProfile = $room?->owner?->profile;
    $hostName    = $hostProfile?->display_name ?? $room?->owner?->guftagu_id ?? 'Host';
    $hostId      = $room?->room_code ?? '—';
    $roomTitle   = $room?->name ?? 'Mehfil';
    $diamonds    = $room?->total_diamonds_received ?? 0;
    $listeners   = $room?->listener_count ?? 0;

    // Real seats if a $room was bound (routes/web.php eager-loads seats.user.profile);
    // a small static fallback otherwise, so the page still renders with no data at all.
    $seatsData = $room?->seats?->map(fn ($seat) => [
        'seat_number' => $seat->seat_number,
        'locked'      => $seat->is_locked,
        'is_vip'      => $seat->is_vip,
        'muted'       => $seat->isEffectivelyMuted(),
        'user'        => $seat->user === null ? null : [
            'id'     => $seat->user->id,
            'uuid'   => $seat->user->uuid,
            'name'   => $seat->user->profile?->display_name ?? $seat->user->guftagu_id,
            'avatar' => $seat->user->profile?->avatar_url,
        ],
    ])->values() ?? collect([
        ['seat_number' => 1, 'locked' => false, 'is_vip' => false, 'muted' => false, 'user' => null],
        ['seat_number' => 2, 'locked' => false, 'is_vip' => true,  'muted' => false, 'user' => null],
        ['seat_number' => 3, 'locked' => false, 'is_vip' => false, 'muted' => false, 'user' => null],
        ['seat_number' => 4, 'locked' => false, 'is_vip' => false, 'muted' => false, 'user' => null],
        ['seat_number' => 5, 'locked' => false, 'is_vip' => false, 'muted' => false, 'user' => null],
        ['seat_number' => 6, 'locked' => false, 'is_vip' => false, 'muted' => false, 'user' => null],
        ['seat_number' => 7, 'locked' => false, 'is_vip' => false, 'muted' => false, 'user' => null],
        ['seat_number' => 8, 'locked' => false, 'is_vip' => false, 'muted' => false, 'user' => null],
        ['seat_number' => 9, 'locked' => false, 'is_vip' => false, 'muted' => false, 'user' => null],
        ['seat_number' => 10, 'locked' => false, 'is_vip' => false, 'muted' => false, 'user' => null],
    ]);

    // Pyramid rows: 2 / 4 / 4 for a 10-seat room, otherwise everything in rows of 4.
    $seatRows = $seatsData->count() === 10
        ? [$seatsData->slice(0, 2)->values(), $seatsData->slice(2, 4)->values(), $seatsData->slice(6, 4)->values()]
        : $seatsData->chunk(4)->values();

    $fmt = static function (int $n): string {
        return $n >= 1000 ? round($n / 1000, 1) . 'k' : (string) $n;
    };
@endphp

<div class="relative mx-auto flex h-screen max-w-md flex-col overflow-hidden text-white select-none"
     style="background: radial-gradient(ellipse at top, #4b6b45 0%, #24391f 45%, #12200f 80%, #0a140a 100%);">

    {{-- forest texture / vignette --}}
    <div class="pointer-events-none absolute inset-0 opacity-25"
         style="background-image: repeating-linear-gradient(100deg, rgba(0,0,0,.45) 0 5px, transparent 5px 46px), repeating-linear-gradient(70deg, rgba(255,244,200,.08) 0 3px, transparent 3px 60px);"></div>
    <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-black/25 via-transparent to-black/60"></div>

    {{-- ============ VIDEO STAGE — whoever currently has their camera on fills the whole
         room as a backdrop, same as Bigo/live-stream apps; header/seats/controls sit on
         top of it (z-10) same as they sit on top of the plain gradient background. ============ --}}
    <div id="gf-stage" class="pointer-events-none absolute inset-0 z-[5] hidden overflow-hidden bg-black">
        <video id="gf-stage-video" autoplay playsinline class="h-full w-full object-cover"></video>
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-black/10 via-transparent to-black/50"></div>
        <div id="gf-stage-label" class="absolute bottom-2 left-3 rounded-full bg-black/50 px-2 py-0.5 text-[11px] font-medium text-white"></div>
    </div>

    {{-- ============ HEADER ============ --}}
    <div class="relative z-10 flex items-start justify-between px-3 pt-4">
        <div class="flex items-center gap-2">
            <img src="https://i.pravatar.cc/80?img=12" alt="" class="h-11 w-11 rounded-full ring-2 ring-white/70 object-cover">
            <div class="leading-tight">
                <div class="text-[13px] font-semibold text-amber-300 drop-shadow">{{ $hostName }}</div>
                <div class="text-[11px] text-white/70">ID:{{ $hostId }}</div>
            </div>
            <button type="button" class="ml-1 grid h-7 w-7 place-items-center rounded-full bg-pink-500 shadow-md shadow-pink-900/40">
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-3.5 w-3.5">
                    <path d="M12 21s-6.7-4.35-9.3-8.1C1.1 10.6 1.6 7.4 4.2 5.9c2.2-1.3 4.7-.6 6.1 1.2.5.6 1.4.6 1.9 0 1.4-1.8 3.9-2.5 6.1-1.2 2.6 1.5 3.1 4.7 1.5 7-2.6 3.75-9.3 8.1-9.3 8.1z"/>
                </svg>
            </button>
        </div>

        <div class="flex items-center gap-2">
            <div class="flex items-center gap-1 rounded-full bg-black/35 py-1 pl-1 pr-2.5 backdrop-blur-sm">
                <img src="https://i.pravatar.cc/60?img=33" alt="" class="h-6 w-6 rounded-full object-cover">
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-3 w-3 text-white/80">
                    <path d="M12 12a4 4 0 100-8 4 4 0 000 8zm0 2c-3.3 0-8 1.66-8 4.5V21h16v-2.5c0-2.84-4.7-4.5-8-4.5z"/>
                </svg>
                <span id="gf-listener-count" class="text-[11px] font-medium">{{ $listeners }}</span>
            </div>
            <button type="button" class="grid h-8 w-8 place-items-center rounded-full bg-black/35 backdrop-blur-sm">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4">
                    <path d="M14 4h6v6M20 4l-7 7M10 20H4v-6M4 20l7-7" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
            <button type="button" class="grid h-8 w-8 place-items-center rounded-full bg-black/35 backdrop-blur-sm">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4">
                    <path d="M12 3v9" stroke-linecap="round"/>
                    <path d="M6 6a8 8 0 1012 0" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
    </div>

    {{-- score / voice / settings row --}}
    <div class="relative z-10 mt-3 flex items-center justify-between px-3">
        <div class="flex items-center gap-2">
            <div class="flex items-center gap-1.5 rounded-full bg-black/35 px-3 py-1 backdrop-blur-sm">
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4 text-amber-400">
                    <path d="M5 4h14v2h2v2a4 4 0 01-4 4h-.2A6 6 0 0113 15.9V18h3v2H8v-2h3v-2.1A6 6 0 017.2 12H7a4 4 0 01-4-4V6h2V4zm0 4a2 2 0 002 2h.05A6 6 0 015 8V6H5v2zm14 0V6h-.05c0 .68-.09 1.35-.25 2H19a2 2 0 002-2h-2z"/>
                </svg>
                <span class="text-[12px] font-semibold text-amber-300">{{ $fmt($diamonds) }}</span>
            </div>
        </div>
        <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-black/35 backdrop-blur-sm">
            <svg viewBox="0 0 24 24" fill="currentColor" class="h-4.5 w-4.5">
                <path d="M19.4 13a7.4 7.4 0 000-2l2-1.6-2-3.4-2.4 1a7.6 7.6 0 00-1.7-1L15 3h-4l-.3 2.4a7.6 7.6 0 00-1.7 1l-2.4-1-2 3.4L6.6 11a7.4 7.4 0 000 2l-2 1.6 2 3.4 2.4-1a7.6 7.6 0 001.7 1L11 21h4l.3-2.4a7.6 7.6 0 001.7-1l2.4 1 2-3.4-2-1.6zM13 15.5a3.5 3.5 0 110-7 3.5 3.5 0 010 7z"/>
            </svg>
        </button>
    </div>

    {{-- ============ SEATS ============ --}}
    <div class="relative z-10 flex flex-1 flex-col justify-center gap-5 px-4">
        @foreach ($seatRows as $row)
            <div class="grid grid-cols-4 items-start justify-items-center gap-x-2">
                @php $offset = 4 - count($row); @endphp
                @foreach ($row as $i => $seat)
                    <button type="button"
                            data-seat="{{ $seat['seat_number'] }}"
                            data-locked="{{ $seat['locked'] ? '1' : '0' }}"
                            data-user-id="{{ $seat['user']['id'] ?? '' }}"
                            data-user-uuid="{{ $seat['user']['uuid'] ?? '' }}"
                            class="gf-seat flex flex-col items-center gap-1" style="grid-column: {{ intdiv($offset, 2) + $i + 1 }}">
                        <div class="gf-seat-ring relative grid h-14 w-14 place-items-center rounded-full shadow-lg {{ $seat['is_vip'] ? 'bg-gradient-to-b from-amber-400 to-amber-600 ring-2 ring-amber-200' : 'bg-gradient-to-b from-fuchsia-800/70 to-fuchsia-950/70 ring-1 ring-white/10' }}">
                            <span class="gf-seat-avatar contents">
                                @if (!empty($seat['user']))
                                    <img src="{{ $seat['user']['avatar'] ?? 'https://i.pravatar.cc/100?u='.$seat['user']['uuid'] }}" alt="" class="h-full w-full rounded-full object-cover">
                                @else
                                    <svg viewBox="0 0 24 24" fill="currentColor" class="h-6 w-6 {{ $seat['is_vip'] ? 'text-amber-900/70' : 'text-pink-200/90' }}">
                                        <path d="M6 10V7a6 6 0 1112 0v3h1a1 1 0 011 1v7a2 2 0 01-2 2h-1v-4a1 1 0 00-1-1H8a1 1 0 00-1 1v4H6a2 2 0 01-2-2v-7a1 1 0 011-1h1zm2 0h8V7a4 4 0 10-8 0v3z"/>
                                    </svg>
                                @endif
                            </span>
                            @if ($seat['locked'])
                                <svg viewBox="0 0 24 24" fill="currentColor" class="absolute inset-0 m-auto h-5 w-5 text-white/70">
                                    <path d="M12 2a4 4 0 00-4 4v3H7a1 1 0 00-1 1v9a1 1 0 001 1h10a1 1 0 001-1v-9a1 1 0 00-1-1h-1V6a4 4 0 00-4-4zm-2 7V6a2 2 0 114 0v3h-4z"/>
                                </svg>
                            @endif
                            <svg class="gf-seat-muted absolute -bottom-0.5 -right-0.5 hidden h-5 w-5 rounded-full bg-red-600 p-0.5 text-white ring-2 ring-black/40" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M16.5 12c0-.23-.03-.46-.06-.68l1.68-1.68a6.5 6.5 0 01-.12 4.85l-1.5-1.5c0-.32 0-.63 0-.99zM19 12c0 .94-.2 1.83-.55 2.65l1.45 1.45A8.96 8.96 0 0021 12h-2zM4.27 3L3 4.27l6 6V12a3 3 0 004.7 2.47l1.5 1.5A4.98 4.98 0 0112 17a5 5 0 01-5-5H5a7 7 0 006 6.92V21h2v-2.08c.7-.08 1.36-.28 1.96-.57L19.73 21 21 19.73 4.27 3zM15 11.16V6a3 3 0 00-5.94-.6l5.94 5.76z"/>
                            </svg>
                        </div>
                        <span class="gf-seat-name text-[11px] text-white/80">{{ !empty($seat['user']) ? \Illuminate\Support\Str::limit($seat['user']['name'], 8) : $seat['seat_number'] }}</span>
                    </button>
                @endforeach
            </div>
        @endforeach
    </div>

    {{-- welcome banner --}}
    <div class="relative z-10 mx-3 rounded-2xl bg-black/35 px-4 py-3 text-center text-[12.5px] leading-relaxed text-amber-200/90 backdrop-blur-sm">
        Welcome to {{ $roomTitle }}, Please comply with platform user regulations, chat with others politely and wish you have a pleasant time here.
    </div>

    {{-- ============ FLOATING WIDGETS ============ --}}
    <div class="pointer-events-none absolute bottom-[124px] right-3 z-20 flex flex-col items-center gap-3">
        <div class="pointer-events-auto flex flex-col items-center gap-1">
            <div class="grid h-11 w-11 place-items-center rounded-full bg-slate-800/80 ring-1 ring-white/10">
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5 text-orange-400">
                    <path d="M12 2c3 2 5 6 5 10 0 2-.6 3.6-1.5 5l1.5 3-3-1a7 7 0 01-4 0l-3 1 1.5-3C7.6 15.6 7 14 7 12c0-4 2-8 5-10zm0 6a2 2 0 100 4 2 2 0 000-4z"/>
                </svg>
            </div>
            <span class="text-[10px] text-white/80">0/100k</span>
        </div>
        <div class="pointer-events-auto flex flex-col items-center gap-1">
            <div class="grid h-11 w-11 place-items-center rounded-full bg-red-600 ring-2 ring-yellow-400">
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5 text-yellow-300">
                    <path d="M12 2l2.2 4.5L19 7.3l-3.5 3.4.8 4.8L12 13.3 7.7 15.5l.8-4.8L5 7.3l4.8-.8z"/>
                </svg>
            </div>
            <span class="text-[10px] font-medium text-white/90">Lucky Wheel</span>
        </div>
    </div>

    {{-- toast --}}
    <div id="gf-toast" class="pointer-events-none absolute left-1/2 top-16 z-30 hidden -translate-x-1/2 rounded-full bg-black/80 px-4 py-2 text-xs font-medium text-white shadow-lg"></div>

    {{-- ============ ACTIVITY + INPUT FOOTER ============ --}}
    <div class="relative z-10 px-3 pb-3 pt-2">
        <div class="mb-2 flex items-center gap-2 rounded-full bg-black/40 px-3 py-1.5 text-[11px] backdrop-blur-sm">
            <span class="flex items-center gap-1 text-sky-300">
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-3.5 w-3.5"><path d="M12 2l4 6h5l-9 14L3 8h5z"/></svg>00
            </span>
            <span class="flex items-center gap-1 text-emerald-300">
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-3.5 w-3.5"><path d="M12 3l7 5-2.7 8H7.7L5 8z"/></svg>00
            </span>
            <span id="gf-activity" class="min-w-0 flex-1 truncate text-white/70">Welcome to {{ $roomTitle }}</span>
        </div>

        <div class="flex items-center gap-2">
            <input type="text" placeholder="Say Something..."
                   class="min-w-0 flex-1 rounded-full bg-black/40 px-4 py-2.5 text-sm text-white placeholder-white/50 outline-none backdrop-blur-sm">
        </div>

        <div class="mt-2 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-black/35 backdrop-blur-sm">
                    <svg viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4"><path d="M4 9v6h4l5 5V4L8 9H4z"/></svg>
                </button>
                {{-- Mic toggle: hidden until this viewer is seated. --}}
                <button type="button" id="gf-mic-btn" class="hidden h-9 w-9 place-items-center rounded-full bg-emerald-500 backdrop-blur-sm">
                    <svg id="gf-mic-icon-on" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4">
                        <path d="M12 14a3 3 0 003-3V6a3 3 0 10-6 0v5a3 3 0 003 3zm5-3a5 5 0 01-10 0H5a7 7 0 006 6.92V21h2v-3.08A7 7 0 0019 11h-2z"/>
                    </svg>
                    <svg id="gf-mic-icon-off" viewBox="0 0 24 24" fill="currentColor" class="hidden h-4 w-4">
                        <path d="M19 11h-2a5 5 0 01-.34 1.8l1.45 1.45A6.96 6.96 0 0019 11zM4.27 3L3 4.27l6 6V11a3 3 0 004.7 2.47l1.5 1.5A4.98 4.98 0 0112 16a5 5 0 01-5-5H5a7 7 0 006 6.92V21h2v-3.08c.7-.08 1.36-.28 1.96-.57L19.73 21 21 19.73 4.27 3zM15 10.16V6a3 3 0 00-5.94-.6l5.94 5.76z"/>
                    </svg>
                </button>
                {{-- Camera toggle: hidden until this viewer is seated, off by default. --}}
                <button type="button" id="gf-camera-btn" class="hidden h-9 w-9 place-items-center rounded-full bg-black/35 backdrop-blur-sm">
                    <svg id="gf-camera-icon-on" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4">
                        <path d="M17 10.5V7a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h12a1 1 0 001-1v-3.5l4 4v-11z"/>
                    </svg>
                    <svg id="gf-camera-icon-off" viewBox="0 0 24 24" fill="currentColor" class="hidden h-4 w-4">
                        <path d="M4.27 3L3 4.27 5.73 7H4a1 1 0 00-1 1v10a1 1 0 001 1h12a1 1 0 001-1v-.73l2.73 2.73L21 19.73 4.27 3zM16 16H5V9h.73L16 19.27V16zm1-5.5V7a1 1 0 00-1-1H8.27l2 2H16v2.27l1 1V7.5l4-4v11l-4-4z"/>
                    </svg>
                </button>
                <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-black/35 backdrop-blur-sm">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4">
                        <path d="M3 6h18v12H3z" stroke-linejoin="round"/><path d="M3 6l9 7 9-7" stroke-linejoin="round"/>
                    </svg>
                </button>
                <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-black/35 backdrop-blur-sm">
                    <svg viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4">
                        <path d="M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 0h6v6h-6v-6z"/>
                    </svg>
                </button>
            </div>
            <div class="flex items-center gap-2.5">
                <button type="button" class="grid h-11 w-11 place-items-center rounded-full bg-gradient-to-b from-pink-400 to-fuchsia-600 shadow-lg shadow-fuchsia-900/40">
                    <svg viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5 text-white">
                        <path d="M20 8h-2.2a3 3 0 00-4.8-3.4L12 5.5l-1-.9A3 3 0 006.2 8H4a1 1 0 00-1 1v2a1 1 0 001 1v7a2 2 0 002 2h12a2 2 0 002-2v-7a1 1 0 001-1V9a1 1 0 00-1-1zM11 20H6v-7h5v7zm0-9H5v-1h6v1zm0-3.5a1.5 1.5 0 11-1.5-1.5c.83 0 1.5.67 1.5 1.5zm2 3.5V10h6v1h-6zm0 2h5v7h-5v-7zm1.5-6a1.5 1.5 0 111.5-1.5c0 .83-.67 1.5-1.5 1.5z"/>
                    </svg>
                </button>
                <button type="button" class="grid h-11 w-11 place-items-center rounded-full bg-gradient-to-b from-orange-400 to-red-600 shadow-lg shadow-red-900/40">
                    <svg viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5 text-white">
                        <path d="M6 9a2 2 0 012-2h8a2 2 0 012 2l1 6a2 2 0 01-2 2.4 2 2 0 01-1.6-.8L14 15h-4l-1.4 1.6A2 2 0 015 16 2 2 0 013 13.8l1-4.8zM9 10v1H8v1h1v1h1v-1h1v-1h-1v-1H9zm6.5 1.5a1 1 0 100 2 1 1 0 000-2zm-2 2.5a1 1 0 100 2 1 1 0 000-2z"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    {{-- remote audio sinks — never visible, just where <audio> elements for each peer live --}}
    <div id="gf-audio-sinks" class="hidden"></div>

    {{-- ============ DEV LOGIN OVERLAY (local only — real client authenticates elsewhere) ============ --}}
    @if ($demoUsers->isNotEmpty())
        <div id="gf-dev-login" class="absolute inset-0 z-40 flex flex-col items-center justify-center gap-4 bg-black/85 px-8 text-center">
            <p class="text-sm text-white/80">Local test only — pick who you are to try the seat + voice chat flow.<br>Open this same URL in a second tab as a different user to hear them.</p>
            <select id="gf-dev-user" class="w-64 rounded-lg bg-white/10 px-3 py-2 text-sm text-white outline-none">
                @foreach ($demoUsers as $u)
                    <option value="{{ $u->id }}">{{ $u->profile?->display_name ?? $u->guftagu_id }}</option>
                @endforeach
            </select>
            <button id="gf-dev-enter" type="button" class="rounded-full bg-emerald-500 px-6 py-2 text-sm font-semibold text-white">Enter room</button>
        </div>
    @endif

    {{-- ============ DEBUG PANEL — testing only, shows what the voice-chat pipeline is doing ============ --}}
    {{-- Collapsed by default: it sits over the mic/camera control row, so an expanded panel would block taps on those buttons. --}}
    <div id="gf-debug" class="absolute inset-x-0 bottom-0 z-50 overflow-y-auto bg-black/90 px-2 py-1.5 font-mono text-[10px] leading-snug text-lime-300">
        <div class="flex items-center justify-between text-white/60">
            <span>debug log</span>
            <span class="flex items-center gap-1.5">
                <button id="gf-debug-toggle" type="button" class="rounded bg-white/10 px-1.5">show</button>
                <button id="gf-debug-copy" type="button" class="rounded bg-white/10 px-1.5">copy</button>
                <button id="gf-debug-clear" type="button" class="rounded bg-white/10 px-1.5">clear</button>
            </span>
        </div>
        <div id="gf-debug-log" class="hidden mt-1 max-h-40 overflow-y-auto"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/pusher-js@8.4.0/dist/web/pusher.min.js"></script>
<script>
(function () {
    'use strict';

    var CONFIG = {
        roomUuid: @json($room->uuid ?? null),
        apiBase: '/api/v1',
        broadcastAuthUrl: '/broadcasting/auth',
        reverbKey: @json(config('broadcasting.connections.reverb.key')),
        reverbPort: @json((int) config('broadcasting.connections.reverb.options.port')),
        iceServers: [{ urls: 'stun:stun.l.google.com:19302' }],
    };

    if (!CONFIG.roomUuid) {
        return; // no room bound — static markup only, nothing to wire up.
    }

    var tokenKey = 'gf_token_' + CONFIG.roomUuid;

    var state = {
        token: null,
        myUuid: null,
        mySeat: null,
        localStream: null,
        micEnabled: true,
        cameraEnabled: false,
        localVideoTrack: null,
        pusher: null,
        channel: null,
        peers: {}, // remote user uuid -> RTCPeerConnection
        videoSenders: {}, // remote user uuid -> RTCRtpSender, kept around so toggling the camera back on reuses it (see stopLocalCamera)
        seatedUsers: {}, // seat_number -> {uuid, display_name, avatar_url}
        roomMembers: {}, // uuid -> {uuid, name, avatar} — everyone present, seated or not
    };

    // Whoever currently has their camera on is shown big in #gf-stage, not in
    // their small seat circle. `order` tracks activation order so that if more
    // than one camera is on at once, the most recently turned-on one wins the
    // spot — turning that one off falls back to whichever is next most recent.
    var stage = { order: [], entries: {} };

    function stageShow(uuid, stream, name, isLocal) {
        if (stage.order.indexOf(uuid) === -1) stage.order.push(uuid);
        stage.entries[uuid] = { stream: stream, name: name, isLocal: !!isLocal };
        renderStage();
    }

    function stageHide(uuid) {
        var idx = stage.order.indexOf(uuid);
        if (idx !== -1) stage.order.splice(idx, 1);
        delete stage.entries[uuid];
        renderStage();
    }

    function renderStage() {
        var stageEl = document.getElementById('gf-stage');
        var videoEl = document.getElementById('gf-stage-video');
        var labelEl = document.getElementById('gf-stage-label');
        var activeUuid = stage.order[stage.order.length - 1];
        if (!activeUuid) {
            stageEl.classList.add('hidden');
            videoEl.srcObject = null;
            return;
        }
        var entry = stage.entries[activeUuid];
        videoEl.srcObject = entry.stream;
        videoEl.muted = entry.isLocal; // never play back my own mic to myself
        // Mirror only my own camera — a front camera is naturally reversed, so
        // flipping it back makes my movements match what I'd see in a mirror.
        // A remote peer's video is never flipped; that would look backwards to
        // everyone else watching them.
        videoEl.style.transform = entry.isLocal ? 'scaleX(-1)' : 'none';
        videoEl.play().catch(function () {});
        labelEl.textContent = entry.isLocal ? 'You' : (entry.name || '');
        stageEl.classList.remove('hidden');
    }

    function toast(message) {
        var el = document.getElementById('gf-toast');
        el.textContent = message;
        el.classList.remove('hidden');
        clearTimeout(toast._t);
        toast._t = setTimeout(function () { el.classList.add('hidden'); }, 2500);
    }

    // getUserMedia only exists in a "secure context" — https, or localhost, or an
    // origin the browser has been explicitly told to trust (chrome://flags/
    // #unsafely-treat-insecure-origin-as-secure). Off that list, the whole
    // mediaDevices object is missing rather than throwing a permission error, so
    // this turns that into an actionable message instead of a raw TypeError.
    function getMic() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            return Promise.reject(new Error(
                'Microphone API unavailable — this page is not in a secure context here. ' +
                'Add ' + window.location.origin + ' to chrome://flags/#unsafely-treat-insecure-origin-as-secure, ' +
                'enable it, and relaunch the browser.'
            ));
        }
        return navigator.mediaDevices.getUserMedia({ audio: true });
    }

    function getCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            return Promise.reject(new Error(
                'Camera API unavailable — this page is not in a secure context here. ' +
                'Add ' + window.location.origin + ' to chrome://flags/#unsafely-treat-insecure-origin-as-secure, ' +
                'enable it, and relaunch the browser.'
            ));
        }
        return navigator.mediaDevices.getUserMedia({ video: true });
    }

    // Testing aid: every log() call shows up both in devtools console and in the
    // on-page panel, so this is readable from a phone with no devtools attached.
    var debugLogEl = document.getElementById('gf-debug-log');
    function log(msg) {
        var line = '[' + new Date().toISOString().slice(11, 19) + '] ' + msg;
        console.log('[gf]', msg);
        if (debugLogEl) {
            var row = document.createElement('div');
            row.textContent = line;
            debugLogEl.appendChild(row);
            while (debugLogEl.children.length > 200) debugLogEl.removeChild(debugLogEl.firstChild);
            document.getElementById('gf-debug').scrollTop = document.getElementById('gf-debug').scrollHeight;
        }
    }
    var clearBtn = document.getElementById('gf-debug-clear');
    if (clearBtn) clearBtn.addEventListener('click', function () { debugLogEl.innerHTML = ''; });

    var copyBtn = document.getElementById('gf-debug-copy');
    if (copyBtn && debugLogEl) {
        copyBtn.addEventListener('click', function () {
            var text = Array.prototype.map.call(debugLogEl.children, function (row) { return row.textContent; }).join('\n');
            var done = function () { copyBtn.textContent = 'copied'; setTimeout(function () { copyBtn.textContent = 'copy'; }, 1200); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done).catch(done);
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(ta);
                done();
            }
        });
    }

    // Collapsed by default so it doesn't block taps on the mic/camera row beneath it.
    var toggleBtn = document.getElementById('gf-debug-toggle');
    if (toggleBtn && debugLogEl) {
        toggleBtn.addEventListener('click', function () {
            var hidden = debugLogEl.classList.toggle('hidden');
            toggleBtn.textContent = hidden ? 'show' : 'hide';
        });
    }

    function api(path, options) {
        options = options || {};
        var headers = Object.assign({ Accept: 'application/json' }, options.headers || {});
        if (state.token) headers.Authorization = 'Bearer ' + state.token;
        if (options.body) headers['Content-Type'] = 'application/json';

        return fetch(CONFIG.apiBase + path, Object.assign({}, options, { headers: headers }))
            .then(function (res) {
                return res.json().then(function (json) {
                    if (!res.ok || json.success === false) {
                        var msg = (json.error && json.message) || json.message || 'Something went wrong';
                        throw new Error(msg);
                    }
                    return json;
                });
            });
    }

    // -------------------------------------------------------------- dev login

    var overlay = document.getElementById('gf-dev-login');

    function startAsDevUser(userId) {
        fetch(CONFIG.apiBase + '/dev/login-as/' + userId, { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                state.token = json.data.token;
                sessionStorage.setItem(tokenKey, JSON.stringify({ token: state.token }));
                if (overlay) overlay.remove();
                boot();
            })
            .catch(function () { toast('Could not sign in as that demo user.'); });
    }

    var stored = sessionStorage.getItem(tokenKey);
    if (stored) {
        try { state.token = JSON.parse(stored).token; } catch (e) { /* ignore */ }
    }

    if (overlay) {
        document.getElementById('gf-dev-enter').addEventListener('click', function () {
            startAsDevUser(document.getElementById('gf-dev-user').value);
        });
        if (state.token) overlay.remove();
    }

    if (state.token) {
        boot();
    } else if (!overlay) {
        toast('No session — open this page from the app with a valid token.');
    }

    // ------------------------------------------------------------------ boot

    function boot() {
        log('boot: joining room…');
        api('/rooms/' + CONFIG.roomUuid + '/join', { method: 'POST' })
            .then(function () { return api('/rooms/' + CONFIG.roomUuid + '/state'); })
            .then(function (json) {
                log('boot: state loaded, my seat = ' + (json.data.my && json.data.my.seat_number));
                applySnapshot(json.data);
                connectSocket();
            })
            .catch(function (err) { log('boot FAILED: ' + err.message); toast(err.message); });
    }

    function applySnapshot(data) {
        (data.seats || []).forEach(function (seat) {
            renderSeat(seat);
            if (seat.user) state.seatedUsers[seat.seat_number] = seat.user;
        });
        state.mySeat = data.my ? data.my.seat_number : null;
        if (state.mySeat) {
            var mine = (data.seats || []).find(function (s) { return s.seat_number === state.mySeat; });
            if (mine && mine.user) state.myUuid = mine.user.uuid;
            enterSeatUi(state.mySeat, data.my.muted);

            // Reconnecting while already seated (e.g. a page refresh) — no fresh
            // seat.occupied fires for a seat that didn't change, so this client has
            // to be the one reaching out to everyone already on the floor, speakers
            // and plain listeners alike.
            getMic().then(function (stream) {
                log('mic: got local stream on reconnect (' + stream.getAudioTracks().length + ' audio track(s))');
                state.localStream = stream;
                state.localStream.getAudioTracks().forEach(function (t) { t.enabled = state.micEnabled; });
                maybeCallEveryone();
            }).catch(function (err) { log('mic FAILED on reconnect: ' + err.name + ' ' + err.message); toast('Microphone access is needed to talk on your seat.'); });
        }
    }

    // -------------------------------------------------------------- sockets

    function connectSocket() {
        // Default: same host the browser used to load this page, not the server's
        // own .env value — a phone loading the page via the LAN IP has to reach
        // Reverb at that same LAN IP; ws to 127.0.0.1 from the phone means "the
        // phone itself", which is never where Reverb is running.
        //
        // That default breaks under a tunnel (ngrok, etc.): the app and Reverb get
        // two *different* public hostnames, one tunnel each, both normally on 443.
        // ?ws_host=&ws_port=&ws_tls= on the page URL overrides each piece for
        // exactly that case — e.g. append
        // ?ws_host=xyz.ngrok-free.dev&ws_port=443&ws_tls=1 pointing at a second
        // `ngrok http 8080` tunnel.
        var qs = new URLSearchParams(window.location.search);
        var wsHost = qs.get('ws_host') || window.location.hostname;
        var wsPort = qs.get('ws_port') ? parseInt(qs.get('ws_port'), 10) : CONFIG.reverbPort;
        var wsTls = qs.has('ws_tls') ? qs.get('ws_tls') === '1' : window.location.protocol === 'https:';

        log('socket: connecting to ' + wsHost + ':' + wsPort + ' (tls=' + wsTls + ') …');
        var Pusher = window.Pusher;
        state.pusher = new Pusher(CONFIG.reverbKey, {
            cluster: 'mt1',
            wsHost: wsHost,
            wsPort: wsPort,
            wssPort: wsPort,
            forceTLS: wsTls,
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
            channelAuthorization: {
                customHandler: function (params, callback) {
                    fetch(CONFIG.broadcastAuthUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            Authorization: 'Bearer ' + state.token,
                            Accept: 'application/json',
                        },
                        body: 'socket_id=' + encodeURIComponent(params.socketId) + '&channel_name=' + encodeURIComponent(params.channelName),
                    })
                        .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
                        .then(function (r) { r.ok ? callback(null, r.data) : callback(new Error('auth failed'), null); })
                        .catch(function (err) { callback(err, null); });
                },
            },
        });

        state.pusher.connection.bind('state_change', function (s) {
            log('socket: connection ' + s.previous + ' -> ' + s.current);
        });
        state.pusher.connection.bind('error', function (err) {
            log('socket ERROR: ' + JSON.stringify(err && err.error ? err.error.data || err.error : err));
        });

        state.channel = state.pusher.subscribe('presence-room.' + CONFIG.roomUuid);

        state.channel.bind('pusher:subscription_error', function (err) {
            log('subscription FAILED: ' + JSON.stringify(err));
        });

        // Everyone present, seated or not — a speaker has to reach plain listeners
        // too, and listeners are never the one who calls, so this list is what a
        // newly-seated speaker calls out to.
        state.channel.bind('pusher:subscription_succeeded', function (members) {
            members.each(function (m) { state.roomMembers[m.info.uuid] = m.info; });
            log('subscribed OK. ' + members.count + ' member(s) in room: ' + Object.keys(state.roomMembers).map(function (u) { return state.roomMembers[u].name; }).join(', '));
            maybeCallEveryone();
        });

        state.channel.bind('pusher:member_added', function (m) {
            state.roomMembers[m.info.uuid] = m.info;
            log('member_added: ' + m.info.name);
            document.getElementById('gf-activity').textContent = (m.info.name || 'Someone') + ' entered the room';
            ensureConnectionTo(m.info.uuid);
        });

        state.channel.bind('pusher:member_removed', function (m) {
            log('member_removed: ' + (m.info && m.info.name));
            delete state.roomMembers[m.info.uuid];
            closePeer(m.info.uuid);
        });

        state.channel.bind('seat.occupied', function (data) {
            log('seat.occupied: seat ' + data.seat_number + ' = ' + data.user.display_name + (data.user.uuid === state.myUuid ? ' (me)' : ''));
            renderSeat(data);
            state.seatedUsers[data.seat_number] = data.user;

            if (data.user.uuid === state.myUuid) {
                return; // that's my own seat confirming
            }

            // Drop any stale listener-mode leg first — a plain listener who just
            // became a speaker needs a fresh, bidirectional connection, and the old
            // one was created before they had a microphone track to offer.
            closePeer(data.user.uuid);
            ensureConnectionTo(data.user.uuid);
        });

        state.channel.bind('seat.vacated', function (data) {
            log('seat.vacated: seat ' + data.seat_number);
            clearSeat(data.seat_number);
            Object.keys(state.seatedUsers).forEach(function (num) {
                if (state.seatedUsers[num] && state.seatedUsers[num].uuid === data.user_uuid) {
                    delete state.seatedUsers[num];
                }
            });
            // The connection stays open on purpose — vacating a seat means "stop
            // talking", not "stop listening". They keep hearing whoever is still
            // seated, and stop being heard the moment their own mic track stops
            // (see leaveSeatUi). Only leaving the room entirely tears this down.
        });

        state.channel.bind('mic.toggled', function (data) {
            setSeatMutedIcon(data.seat_number, data.muted);
        });

        // WebRTC's own ontrack fires the *first* time a peer's camera comes on
        // (that's when the video sender/transceiver is actually created). Every
        // toggle after that reuses the same sender via replaceTrack() — no new
        // SDP, no new ontrack — so this broadcast is what actually drives the
        // stage showing/hiding on later toggles, not the media track itself.
        state.channel.bind('camera.toggled', function (data) {
            log('camera.toggled: seat ' + data.seat_number + ' -> ' + data.camera_on);
            var seatUser = state.seatedUsers[data.seat_number];
            if (!seatUser || seatUser.uuid === state.myUuid) return;

            if (!data.camera_on) {
                stageHide(seatUser.uuid);
                return;
            }
            var pc = state.peers[seatUser.uuid];
            if (!pc) { log('camera.toggled(on) for ' + seatUser.uuid + ' but no peer connection exists'); return; }
            // Prefer the cached stream from the original ontrack event, but fall
            // back to whatever video track this connection currently has — the
            // cache is lost if this peer connection was ever recreated (e.g. a
            // seat.occupied rebuild) after the original ontrack fired.
            var stream = pc._remoteVideoStream;
            if (!stream) {
                var videoReceiver = pc.getReceivers().find(function (r) { return r.track && r.track.kind === 'video'; });
                if (videoReceiver) stream = new MediaStream([videoReceiver.track]);
            }
            if (stream) {
                stageShow(seatUser.uuid, stream, seatUser.display_name || seatUser.name, false);
            } else {
                log('camera.toggled(on) for ' + seatUser.uuid + ' but no video track on this connection yet');
            }
        });

        state.channel.bind('client-signal', function (data) {
            if (!data) return;
            if (data.to !== state.myUuid) return;
            log('signal RECEIVED: ' + data.type + ' from ' + data.from);
            handleSignal(data);
        });
    }

    function sendSignal(toUuid, type, payload) {
        var ok = state.channel.trigger('client-signal', { to: toUuid, from: state.myUuid, type: type, payload: payload });
        log('signal SENT: ' + type + ' to ' + toUuid + (ok ? '' : ' (trigger returned false — client events may be disabled)'));
    }

    // --------------------------------------------------------------- WebRTC
    // Peers are keyed by the remote user's uuid — the same identifier every
    // broadcast event (seat.occupied/vacated) already carries.
    //
    // Only a seated speaker ever originates a call — a plain listener has nothing
    // to send and just answers. That alone gets every speaker connected to every
    // listener. For two speakers (who both have something to send each other),
    // whoever calls first would otherwise race; comparing uuids picks one side
    // deterministically so only one offer goes out per pair.

    function isSpeakerUuid(uuid) {
        return Object.keys(state.seatedUsers).some(function (seatNumber) {
            return state.seatedUsers[seatNumber] && state.seatedUsers[seatNumber].uuid === uuid;
        });
    }

    function ensureConnectionTo(remoteUuid) {
        if (!state.mySeat || !state.localStream || remoteUuid === state.myUuid) return;
        if (isSpeakerUuid(remoteUuid) && state.myUuid > remoteUuid) {
            log('not calling ' + remoteUuid + ' — both speakers, letting them initiate (uuid tie-break)');
            return;
        }
        callPeer(remoteUuid);
    }

    function maybeCallEveryone() {
        if (!state.mySeat || !state.localStream) return;
        Object.keys(state.roomMembers).forEach(ensureConnectionTo);
    }

    function createPeer(remoteUuid) {
        var pc = new RTCPeerConnection({ iceServers: CONFIG.iceServers });
        var sending = false;
        if (state.localStream) {
            sending = true;
            state.localStream.getTracks().forEach(function (t) {
                var sender = pc.addTrack(t, state.localStream);
                if (t.kind === 'video') state.videoSenders[remoteUuid] = sender;
            });
        }
        log('peer created for ' + remoteUuid + ' (sending: ' + sending + ')');

        // Fires once right after the addTrack() calls above (the initial call),
        // and again any time a track is later added/removed — turning the camera
        // on or off mid-call reuses this exact same renegotiation path, no
        // separate "send an offer" code needed anywhere else.
        //
        // A renegotiation that arrives while another one is still in flight (e.g.
        // camera off then straight back on, before the "off" offer/answer round
        // finished) can't send an offer right away — the signaling state isn't
        // 'stable'. It used to just be dropped here, silently losing that change
        // until the page was refreshed. Now it's remembered and replayed as soon
        // as the connection is stable again.
        function negotiate() {
            if (pc.signalingState !== 'stable') {
                pc._renegotiatePending = true;
                log('deferring negotiation with ' + remoteUuid + ' — mid-handshake (' + pc.signalingState + ')');
                return;
            }
            log('negotiating with ' + remoteUuid + ' (offer)');
            pc.createOffer()
                .then(function (offer) { return pc.setLocalDescription(offer); })
                .then(function () { sendSignal(remoteUuid, 'offer', pc.localDescription); })
                .catch(function (err) { log('negotiation offer FAILED for ' + remoteUuid + ': ' + err.message); });
        }
        pc.onnegotiationneeded = negotiate;
        pc.onsignalingstatechange = function () {
            if (pc.signalingState === 'stable' && pc._renegotiatePending) {
                pc._renegotiatePending = false;
                log('replaying deferred negotiation with ' + remoteUuid);
                negotiate();
            }
        };
        pc.onicecandidate = function (e) {
            if (e.candidate) sendSignal(remoteUuid, 'ice', e.candidate);
        };
        pc.oniceconnectionstatechange = function () {
            log('ICE state with ' + remoteUuid + ': ' + pc.iceConnectionState);
        };
        pc.ontrack = function (e) {
            log(e.track.kind.toUpperCase() + ' track received from ' + remoteUuid);
            // Cached so camera.toggled can re-show the same stream on later
            // on/off toggles, which reuse this track via replaceTrack() and so
            // never fire ontrack again (see camera.toggled binding above).
            if (e.track.kind === 'video') pc._remoteVideoStream = e.streams[0];
            attachRemoteMedia(remoteUuid, e.streams[0]);
            e.track.onended = function () {
                log(e.track.kind + ' track from ' + remoteUuid + ' ended');
                if (e.track.kind === 'video') {
                    stageHide(remoteUuid);
                    // Their mic may still be live — make sure it keeps playing
                    // somewhere now that the stage isn't doing it for them.
                    if (e.streams[0] && e.streams[0].getAudioTracks().length > 0) attachAudioSink(remoteUuid, e.streams[0]);
                }
            };
        };
        state.peers[remoteUuid] = pc;
        return pc;
    }

    function callPeer(remoteUuid) {
        if (state.peers[remoteUuid]) return;
        log('calling ' + remoteUuid);
        // No explicit offer here — creating the connection above adds this
        // client's tracks, which alone queues the onnegotiationneeded event
        // that actually sends it.
        createPeer(remoteUuid);
    }

    function handleSignal(data) {
        var from = data.from;

        if (data.type === 'offer') {
            var pc = state.peers[from] || createPeer(from);
            pc.setRemoteDescription(new RTCSessionDescription(data.payload))
                .then(function () { return pc.createAnswer(); })
                .then(function (answer) { return pc.setLocalDescription(answer).then(function () { return answer; }); })
                .then(function (answer) { sendSignal(from, 'answer', answer); })
                .catch(function (err) { log('answer FAILED for ' + from + ': ' + err.message); });
            return;
        }

        if (data.type === 'answer') {
            if (state.peers[from]) {
                state.peers[from].setRemoteDescription(new RTCSessionDescription(data.payload))
                    .catch(function (err) { log('setRemoteDescription(answer) FAILED for ' + from + ': ' + err.message); });
            } else {
                log('answer from ' + from + ' but no peer connection exists — ignoring');
            }
            return;
        }

        if (data.type === 'ice') {
            if (state.peers[from]) {
                state.peers[from].addIceCandidate(new RTCIceCandidate(data.payload)).catch(function (err) { log('addIceCandidate FAILED for ' + from + ': ' + err.message); });
            } else {
                log('ICE candidate from ' + from + ' but no peer connection exists — ignoring');
            }
        }
    }

    // A video track (if the peer has their camera on) shows big in #gf-stage
    // instead of their small seat circle — which plays the stream's audio track
    // too, so the separate hidden `<audio>` sink is only needed when there is no
    // video for that peer.
    function attachRemoteMedia(remoteUuid, stream) {
        if (stream.getVideoTracks().length > 0) {
            var oldAudio = document.getElementById('gf-audio-' + remoteUuid);
            if (oldAudio) oldAudio.remove();

            var seatNumber = seatNumberForUuid(remoteUuid);
            var user = seatNumber && state.seatedUsers[seatNumber];
            var name = (user && (user.display_name || user.name)) || 'Guest';
            stageShow(remoteUuid, stream, name, false);
            log('showing stage video from ' + remoteUuid);
            return;
        }

        attachAudioSink(remoteUuid, stream);
    }

    function attachAudioSink(remoteUuid, stream) {
        var id = 'gf-audio-' + remoteUuid;
        var audio = document.getElementById(id);
        if (!audio) {
            audio = document.createElement('audio');
            audio.id = id;
            audio.autoplay = true;
            document.getElementById('gf-audio-sinks').appendChild(audio);
        }
        audio.srcObject = stream;
        audio.play().then(function () { log('playing audio from ' + remoteUuid); }).catch(function (err) {
            log('audio.play() BLOCKED for ' + remoteUuid + ': ' + err.name + ' — tap anywhere on the page once to unlock autoplay');
        });
    }

    function seatNumberForUuid(uuid) {
        for (var seatNumber in state.seatedUsers) {
            if (state.seatedUsers[seatNumber] && state.seatedUsers[seatNumber].uuid === uuid) return seatNumber;
        }
        return null;
    }

    // Puts a seat back to showing the plain avatar image — used both for my own
    // seat (camera turned off) and a remote one (their video track ended).
    // Preserves whatever locked/muted state is already on screen rather than
    // guessing, since this isn't a full seat.occupied re-render.
    function revertSeatAvatarDisplay(seatNumber) {
        if (!seatNumber) return;
        var el = seatEl(seatNumber);
        if (!el) return;
        var mutedIcon = el.querySelector('.gf-seat-muted');
        renderSeat({
            seat_number: seatNumber,
            user: state.seatedUsers[seatNumber] || null,
            muted: mutedIcon ? !mutedIcon.classList.contains('hidden') : false,
            locked: el.dataset.locked === '1',
        });
    }

    function closePeer(remoteUuid) {
        if (state.peers[remoteUuid]) {
            state.peers[remoteUuid].close();
            delete state.peers[remoteUuid];
        }
        delete state.videoSenders[remoteUuid];
        stageHide(remoteUuid);
        var audio = document.getElementById('gf-audio-' + remoteUuid);
        if (audio) audio.remove();
    }

    // ---------------------------------------------------------------- seats

    function seatEl(n) { return document.querySelector('.gf-seat[data-seat="' + n + '"]'); }

    function renderSeat(seat) {
        var el = seatEl(seat.seat_number);
        if (!el) return;
        el.dataset.locked = seat.locked ? '1' : '0';
        el.dataset.userId = seat.user ? seat.user.id : '';
        el.dataset.userUuid = seat.user ? seat.user.uuid : '';

        var avatarSlot = el.querySelector('.gf-seat-avatar');
        var nameEl = el.querySelector('.gf-seat-name');
        if (seat.user) {
            avatarSlot.innerHTML = '<img src="' + (seat.user.avatar_url || seat.user.avatar || ('https://i.pravatar.cc/100?u=' + seat.user.uuid)) + '" class="h-full w-full rounded-full object-cover" alt="">';
            nameEl.textContent = (seat.user.display_name || seat.user.name || 'User').slice(0, 8);
        } else {
            avatarSlot.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor" class="h-6 w-6 text-pink-200/90"><path d="M6 10V7a6 6 0 1112 0v3h1a1 1 0 011 1v7a2 2 0 01-2 2h-1v-4a1 1 0 00-1-1H8a1 1 0 00-1 1v4H6a2 2 0 01-2-2v-7a1 1 0 011-1h1zm2 0h8V7a4 4 0 10-8 0v3z"/></svg>';
            nameEl.textContent = seat.seat_number;
        }
        setSeatMutedIcon(seat.seat_number, !!seat.muted);
    }

    function clearSeat(seatNumber) {
        renderSeat({ seat_number: seatNumber, user: null, muted: false, locked: seatEl(seatNumber) && seatEl(seatNumber).dataset.locked === '1' });
    }

    function setSeatMutedIcon(seatNumber, muted) {
        var el = seatEl(seatNumber);
        if (!el) return;
        var icon = el.querySelector('.gf-seat-muted');
        icon.classList.toggle('hidden', !muted);
    }

    function enterSeatUi(seatNumber, muted) {
        state.mySeat = seatNumber;
        state.micEnabled = !muted;
        document.getElementById('gf-mic-btn').classList.remove('hidden');
        document.getElementById('gf-mic-btn').classList.add('grid');
        document.getElementById('gf-camera-btn').classList.remove('hidden');
        document.getElementById('gf-camera-btn').classList.add('grid');
        setMicIcon(state.micEnabled);
        setCameraIcon(state.cameraEnabled);
    }

    function leaveSeatUi() {
        var oldSeat = state.mySeat;
        state.mySeat = null;
        document.getElementById('gf-mic-btn').classList.add('hidden');
        document.getElementById('gf-camera-btn').classList.add('hidden');
        // Peer connections stay open on purpose — see the seat.vacated comment in
        // connectSocket(). Stopping the mic/camera tracks is what actually stops
        // this client from being heard/seen; everyone else's audio keeps arriving.
        stopLocalCamera();
        revertSeatAvatarDisplay(oldSeat);
        if (state.localStream) {
            state.localStream.getTracks().forEach(function (t) { t.stop(); });
            state.localStream = null;
        }
    }

    document.querySelectorAll('.gf-seat').forEach(function (el) {
        el.addEventListener('click', function () {
            var n = parseInt(el.dataset.seat, 10);
            var occupiedByMe = state.mySeat === n;
            var occupiedByOther = el.dataset.userId && !occupiedByMe;
            var locked = el.dataset.locked === '1';

            if (locked) { toast('That seat is locked.'); return; }
            if (occupiedByOther) { toast('Someone is already sitting there.'); return; }

            if (occupiedByMe) {
                api('/rooms/' + CONFIG.roomUuid + '/seats/leave', { method: 'POST' })
                    .then(function () { leaveSeatUi(); })
                    .catch(function (err) { toast(err.message); });
                return;
            }

            getMic()
                .then(function (stream) {
                    state.localStream = stream;
                    return api('/rooms/' + CONFIG.roomUuid + '/seats/' + n + '/take', { method: 'POST' });
                })
                .then(function (json) {
                    var seatEntry = json.data.seats.find(function (s) { return s.seat_number === n; });
                    if (seatEntry && seatEntry.user) state.myUuid = seatEntry.user.uuid;
                    // Any connection made while I was still a plain listener was
                    // built without a microphone track — drop it so the fresh
                    // offer/answer below carries my audio too.
                    Object.keys(state.peers).forEach(function (uuid) { closePeer(uuid); });
                    enterSeatUi(n, false);
                    maybeCallEveryone();
                })
                .catch(function (err) {
                    log('take seat FAILED: ' + err.message);
                    if (state.localStream) { state.localStream.getTracks().forEach(function (t) { t.stop(); }); state.localStream = null; }
                    toast(err.message || 'Could not use the microphone.');
                });
        });
    });

    // ------------------------------------------------------------------ mic

    function setMicIcon(on) {
        document.getElementById('gf-mic-icon-on').classList.toggle('hidden', !on);
        document.getElementById('gf-mic-icon-off').classList.toggle('hidden', on);
        document.getElementById('gf-mic-btn').classList.toggle('bg-emerald-500', on);
        document.getElementById('gf-mic-btn').classList.toggle('bg-red-500', !on);
    }

    document.getElementById('gf-mic-btn').addEventListener('click', function () {
        state.micEnabled = !state.micEnabled;
        if (state.localStream) {
            state.localStream.getAudioTracks().forEach(function (t) { t.enabled = state.micEnabled; });
        }
        setMicIcon(state.micEnabled);
        api('/rooms/' + CONFIG.roomUuid + '/mic', { method: 'PATCH', body: JSON.stringify({ muted: !state.micEnabled }) })
            .catch(function (err) { toast(err.message); });
    });

    // ---------------------------------------------------------------- camera

    function setCameraIcon(on) {
        document.getElementById('gf-camera-icon-on').classList.toggle('hidden', on);
        document.getElementById('gf-camera-icon-off').classList.toggle('hidden', !on);
        document.getElementById('gf-camera-btn').classList.toggle('bg-emerald-500', on);
        document.getElementById('gf-camera-btn').classList.toggle('bg-black/35', !on);
    }

    function showLocalVideoPreview(track) {
        stageShow(state.myUuid, new MediaStream([track]), 'You', true);
    }

    // Stops sending video without touching the mic or closing any connection.
    // Used both by the explicit toggle-off and by leaveSeatUi — vacating a seat
    // always drops the camera too.
    //
    // Uses replaceTrack(null) rather than removeTrack() so the sender/transceiver
    // stays put — removeTrack() forces a fresh renegotiation (offer/answer) every
    // single time the camera toggles, and if the *next* toggle (back on) lands
    // before that round-trip finishes, it used to get silently dropped until a
    // page refresh. replaceTrack needs no renegotiation at all, so there's
    // nothing to race.
    function stopLocalCamera() {
        if (!state.localVideoTrack) return;
        var track = state.localVideoTrack;
        Object.keys(state.videoSenders).forEach(function (uuid) {
            state.videoSenders[uuid].replaceTrack(null).catch(function (err) { log('replaceTrack(null) FAILED for ' + uuid + ': ' + err.message); });
        });
        if (state.localStream) state.localStream.removeTrack(track);
        track.stop();
        state.localVideoTrack = null;
        state.cameraEnabled = false;
        setCameraIcon(false);
        stageHide(state.myUuid);
    }

    document.getElementById('gf-camera-btn').addEventListener('click', function () {
        if (!state.mySeat) return;

        if (state.cameraEnabled) {
            stopLocalCamera();
            api('/rooms/' + CONFIG.roomUuid + '/camera', { method: 'PATCH', body: JSON.stringify({ camera_on: false }) })
                .catch(function (err) { toast(err.message); });
            return;
        }

        getCamera().then(function (camStream) {
            var track = camStream.getVideoTracks()[0];
            state.localVideoTrack = track;
            state.localStream.addTrack(track);
            state.cameraEnabled = true;
            setCameraIcon(true);
            showLocalVideoPreview(track);
            // A peer that already has a video sender (camera was on for them
            // before, then off) gets the new track via replaceTrack — no
            // renegotiation needed, and their existing <video> just starts
            // rendering real frames again (see camera.toggled binding).
            // A peer that has never seen my video before needs a real addTrack,
            // which does trigger the one-time renegotiation to create it.
            Object.keys(state.peers).forEach(function (uuid) {
                if (state.videoSenders[uuid]) {
                    state.videoSenders[uuid].replaceTrack(track).catch(function (err) { log('replaceTrack FAILED for ' + uuid + ': ' + err.message); });
                } else {
                    state.videoSenders[uuid] = state.peers[uuid].addTrack(track, state.localStream);
                }
            });
            return api('/rooms/' + CONFIG.roomUuid + '/camera', { method: 'PATCH', body: JSON.stringify({ camera_on: true }) });
        }).catch(function (err) {
            log('camera FAILED: ' + err.message);
            toast(err.message || 'Could not access the camera.');
        });
    });
})();
</script>

</body>
</html>
