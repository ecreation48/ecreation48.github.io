@php
    use App\Models\DiscordMember;
    use App\Models\VoiceEvent;
    use App\Models\VoiceSessionMember;

    $recording = $record->recording;
    $appearance = $globalSettings['appearance'] ?? [];
    $accentColor = $appearance['accent_color'] ?? '#14b8a6';
    $dangerColor = $appearance['danger_color'] ?? '#dc2626';
    $clips = $recording?->tracks()->oldest('captured_from')->get() ?? $record->audioClips()->oldest('captured_from')->get();
    $reportedAt = $record->reported_at ?? $record->created_at ?? now();
    $from = null;
    $until = null;

    foreach ($clips as $clip) {
        $clipDuration = max(1, (int) $clip->duration_seconds);
        $clipUntil = $clip->captured_until?->copy();
        $clipStart = $clipUntil?->copy()->subSeconds($clipDuration) ?? $clip->captured_from?->copy();

        if ($clipStart && ($from === null || $clipStart->lessThan($from))) {
            $from = $clipStart;
        }

        if ($clipUntil && ($until === null || $clipUntil->greaterThan($until))) {
            $until = $clipUntil;
        }
    }

    $from ??= ($recording?->captured_from ?? $reportedAt->copy()->subSeconds(45))->copy();
    $until ??= ($recording?->captured_until ?? $reportedAt->copy()->addSeconds(10))->copy();

    if ($until->lessThanOrEqualTo($from)) {
        $until = $from->copy()->addSeconds(1);
    }

    $memberNames = DiscordMember::query()
        ->where('discord_guild_id', $record->discord_guild_id)
        ->pluck('display_name', 'discord_id');
    $sessionMemberNames = VoiceSessionMember::query()
        ->whereHas('session', fn ($query) => $query->where('discord_guild_id', $record->discord_guild_id))
        ->whereNotNull('display_name')
        ->latest('updated_at')
        ->get()
        ->pluck('display_name', 'discord_user_id');

    $fromUtc = $from->copy()->utc();
    $untilUtc = $until->copy()->utc();
    $duration = max(1, $from->diffInSeconds($until));
    $reportedAtForTimeline = $reportedAt->betweenIncluded($from, $until) ? $reportedAt : $until;
    $reportOffset = max(0, min(100, $from->diffInSeconds($reportedAtForTimeline, false) / $duration * 100));

    $events = VoiceEvent::query()
        ->with(['channel'])
        ->where('discord_guild_id', $record->discord_guild_id)
        ->where(function ($query) use ($record) {
            $query->where('discord_channel_id', $record->discord_channel_id)->orWhereNull('discord_channel_id');
        })
        ->where(function ($query) use ($from, $until, $fromUtc, $untilUtc) {
            $query->whereBetween('occurred_at', [$from, $until])
                ->orWhereBetween('occurred_at', [$fromUtc, $untilUtc]);
        })
        ->oldest('occurred_at')
        ->get()
        ->map(function (VoiceEvent $event) use ($from, $duration, $memberNames, $sessionMemberNames) {
            $offset = max(0, min(100, $from->diffInSeconds($event->occurred_at, false) / $duration * 100));
            $name = data_get($event->payload, 'display_name') ?: $sessionMemberNames->get($event->discord_user_id) ?: $memberNames->get($event->discord_user_id) ?: $event->discord_user_id ?: 'Système';

            return [
                'id' => $event->id,
                'type' => $event->type,
                'label' => $event->label(),
                'tone' => $event->tone(),
                'actor' => $name,
                'userId' => $event->discord_user_id ?: 'system',
                'time' => $event->occurred_at->format('H:i:s'),
                'channel' => $event->channel?->name ?? 'Salon inconnu',
                'offset' => round($offset, 3),
            ];
        })
        ->values();

    $eventNames = $events->where('userId', '!==', 'system')->pluck('actor', 'userId');
    $trackPalette = ['#14b8a6', '#6366f1', '#f59e0b', '#ef4444', '#0ea5e9', '#84cc16', '#a855f7', '#f97316'];
    $tracks = $clips->values()->map(function ($clip, int $index) use ($from, $memberNames, $sessionMemberNames, $eventNames, $trackPalette) {
        $name = $eventNames->get($clip->reported_user_discord_id)
            ?: $sessionMemberNames->get($clip->reported_user_discord_id)
            ?: $memberNames->get($clip->reported_user_discord_id)
            ?: $clip->displayName();
        $clipDuration = max(1, (int) $clip->duration_seconds);
        $endOffset = $clip->captured_until ? max($clipDuration, $from->diffInSeconds($clip->captured_until, false)) : $clipDuration;
        $startOffset = max(0, $endOffset - $clipDuration);

        return [
            'id' => $clip->id,
            'name' => $name,
            'userId' => $clip->reported_user_discord_id,
            'initials' => mb_strtoupper(mb_substr($name, 0, 2)),
            'color' => $trackPalette[$index % count($trackPalette)],
            'url' => route('admin.audio-clips.stream', $clip),
            'duration' => $clipDuration,
            'startOffset' => $startOffset,
            'endOffset' => $endOffset,
            'from' => $clip->captured_from?->format('H:i:s'),
            'until' => $clip->captured_until?->format('H:i:s'),
            'available' => $clip->mime_type === 'audio/wav' && (bool) $clip->resolvedStoragePath(),
        ];
    })->values();
    $availableTrackWindows = $tracks
        ->where('available', true)
        ->map(fn (array $track) => ['start' => (float) $track['startOffset'], 'end' => (float) $track['endOffset']])
        ->sortBy('start')
        ->values();
    $silentZones = collect();
    $cursor = 0.0;

    foreach ($availableTrackWindows as $window) {
        if ($window['start'] > $cursor) {
            $silentZones->push([
                'start' => round($cursor / $duration * 100, 3),
                'width' => round(($window['start'] - $cursor) / $duration * 100, 3),
            ]);
        }

        $cursor = max($cursor, $window['end']);
    }

    if ($cursor < $duration) {
        $silentZones->push([
            'start' => round($cursor / $duration * 100, 3),
            'width' => round(($duration - $cursor) / $duration * 100, 3),
        ]);
    }
    $trackSegments = $tracks->map(function (array $track) use ($duration) {
        $start = max(0, (float) $track['startOffset']);
        $end = min($duration, (float) $track['endOffset']);

        return [
            'id' => $track['id'],
            'name' => $track['name'],
            'color' => $track['color'],
            'start' => round($start / $duration * 100, 3),
            'width' => round(max(0, $end - $start) / $duration * 100, 3),
        ];
    })->values();

    $eventUsers = $events
        ->groupBy('userId')
        ->map(fn ($items) => ['id' => $items->first()['userId'], 'name' => $items->first()['actor'], 'events' => $items->values()])
        ->values();
@endphp

@once
    <link rel="stylesheet" href="{{ asset('vendor/plyr/plyr.css') }}">
    <script src="{{ asset('vendor/plyr/plyr.polyfilled.min.js') }}"></script>
@endonce

<style>
    .vg-review { --vg-accent: {{ $accentColor }}; --vg-danger: {{ $dangerColor }}; overflow: hidden; border: 1px solid #d1d5db; border-radius: 14px; background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, .06); }
    .vg-review__hero { padding: 26px; color: #fff; background: linear-gradient(135deg, #111827 0%, var(--vg-accent) 55%, #4338ca 100%); }
    .vg-review__kicker { color: #99f6e4; font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
    .vg-review__title { margin: 5px 0 0; font-size: 26px; line-height: 1.15; font-weight: 800; }
    .vg-review__copy { max-width: 820px; margin: 10px 0 0; color: #d1d5db; font-size: 14px; }
    .vg-review__transport { display: inline-flex; align-items: center; gap: 6px; padding: 6px; border: 1px solid rgba(255, 255, 255, .16); border-radius: 14px; background: rgba(255, 255, 255, .1); box-shadow: inset 0 1px 0 rgba(255, 255, 255, .08); }
    .vg-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 40px; padding: 9px 14px; border: 1px solid #d1d5db; border-radius: 10px; background: #fff; color: #374151; font-size: 14px; font-weight: 750; text-decoration: none; box-shadow: 0 1px 1px rgba(15, 23, 42, .04); cursor: pointer; }
    .vg-btn:hover { background: #f9fafb; }
    .vg-btn--primary { border-color: var(--vg-accent); background: var(--vg-accent); color: #042f2e; }
    .vg-btn--primary:hover { background: #2dd4bf; }
    .vg-btn--ghost { border-color: rgba(255,255,255,.16); background: rgba(255,255,255,.1); color: #fff; box-shadow: none; }
    .vg-btn--ghost:hover { background: rgba(255,255,255,.16); }
    .vg-review__transport .vg-btn { min-height: 38px; border-radius: 10px; white-space: nowrap; }
    .vg-review__transport .vg-btn--ghost { border-color: transparent; background: transparent; }
    .vg-review__transport .vg-btn--ghost:hover { background: rgba(255,255,255,.14); }
    .vg-btn--active { border-color: #111827; background: #111827; color: #fff; }
    .vg-icon { width: 18px; height: 18px; flex: 0 0 auto; }
    .vg-review__body { display: grid; gap: 24px; padding: 24px; background: #f8fafc; }
    .vg-mixer { display: grid; grid-template-columns: 1fr; gap: 18px; align-items: start; }
    .vg-main-player, .vg-tracks, .vg-timeline { border: 1px solid #e5e7eb; border-radius: 14px; background: #fff; }
    .vg-main-player { padding: 20px; }
    .vg-main-player__label { color: #64748b; font-size: 12px; font-weight: 800; text-transform: uppercase; }
    .vg-main-player__name { margin-top: 6px; color: #111827; font-size: 20px; line-height: 1.2; font-weight: 850; }
    .vg-main-player__hint { margin-top: 6px; color: #64748b; font-size: 13px; }
    .vg-master { display: grid; gap: 14px; margin-top: 18px; padding: 18px; border: 1px solid #e5e7eb; border-radius: 14px; background: #f8fafc; }
    .vg-master-map { position: relative; height: 64px; overflow: hidden; border: 1px solid #dbeafe; border-radius: 13px; background: #eef2ff; }
    .vg-master-map::before { content: ""; position: absolute; inset: 0; background-image: linear-gradient(90deg, rgba(148, 163, 184, .14) 1px, transparent 1px); background-size: 10% 100%; pointer-events: none; }
    .vg-silent-zone { position: absolute; top: 0; bottom: 0; background: repeating-linear-gradient(135deg, rgba(100, 116, 139, .22) 0 7px, rgba(100, 116, 139, .08) 7px 14px); }
    .vg-talk-zone { position: absolute; top: 12px; height: 40px; min-width: 4px; border-radius: 999px; background: var(--track-color); opacity: .72; box-shadow: 0 6px 18px rgba(15, 23, 42, .16); transition: opacity .15s ease, filter .15s ease, transform .15s ease; }
    .vg-talk-zone--dimmed { opacity: .13; filter: grayscale(.8); }
    .vg-talk-zone--isolated { opacity: .95; transform: translateY(-2px); box-shadow: 0 8px 22px rgba(15, 23, 42, .24); }
    .vg-playhead { position: absolute; top: 0; bottom: 0; z-index: 4; width: 2px; background: #111827; transform: translateX(-50%); }
    .vg-playhead::before { content: ""; position: absolute; left: 50%; top: 5px; width: 10px; height: 10px; border-radius: 999px; background: #111827; transform: translateX(-50%); }
    .vg-master-legend { display: flex; flex-wrap: wrap; gap: 8px 12px; color: #475569; font-size: 12px; }
    .vg-master-legend__item { display: inline-flex; align-items: center; gap: 6px; font-weight: 750; }
    .vg-master-legend__swatch { width: 10px; height: 10px; border-radius: 999px; background: var(--track-color); }
    .vg-master-legend__silent { width: 18px; height: 10px; border-radius: 4px; background: repeating-linear-gradient(135deg, rgba(100, 116, 139, .35) 0 4px, rgba(100, 116, 139, .12) 4px 8px); }
    .vg-master__row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .vg-master__time { min-width: 92px; color: #111827; font-size: 13px; font-weight: 850; text-align: right; }
    .vg-master__range { width: 100%; accent-color: var(--vg-accent); cursor: pointer; }
    .vg-master__volume { width: 150px; accent-color: var(--vg-accent); cursor: pointer; }
    .vg-master__state { color: #64748b; font-size: 12px; font-weight: 750; }
    .vg-speakers { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding-top: 2px; }
    .vg-speakers__label { color: #64748b; font-size: 12px; font-weight: 850; text-transform: uppercase; }
    .vg-speaker-chip { display: inline-flex; align-items: center; gap: 7px; min-height: 30px; padding: 5px 10px; border-radius: 999px; background: #ccfbf1; color: #134e4a; font-size: 13px; font-weight: 850; }
    .vg-speaker-chip::before { content: ""; width: 8px; height: 8px; border-radius: 999px; background: var(--vg-accent); box-shadow: 0 0 0 4px rgba(20, 184, 166, .18); }
    .vg-speaker-chip--empty { background: #e5e7eb; color: #64748b; }
    .vg-speaker-chip--empty::before { background: #94a3b8; box-shadow: none; }
    .vg-tracks { overflow: hidden; }
    .vg-tracks__head { padding: 16px 18px; border-bottom: 1px solid #f1f5f9; color: #111827; font-size: 15px; font-weight: 850; }
    .vg-track-row { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: center; padding: 14px 18px; border-top: 1px solid #f1f5f9; }
    .vg-track-row:first-of-type { border-top: 0; }
    .vg-track-row--muted { opacity: .52; }
    .vg-track-row--solo { background: #ecfeff; }
    .vg-track-row--speaking { box-shadow: inset 4px 0 0 var(--vg-accent); background: #f0fdfa; }
    .vg-track-person { display: flex; min-width: 0; align-items: center; gap: 12px; border: 0; background: transparent; padding: 0; text-align: left; cursor: pointer; }
    .vg-track-avatar { display: grid; place-items: center; width: 42px; height: 42px; flex: 0 0 auto; border-radius: 999px; background: #ccfbf1; color: #134e4a; font-size: 13px; font-weight: 900; }
    .vg-track-name { overflow: hidden; color: #111827; font-size: 14px; font-weight: 850; text-overflow: ellipsis; white-space: nowrap; }
    .vg-track-time { margin-top: 3px; color: #64748b; font-size: 12px; }
    .vg-track-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
    .vg-track-zone { grid-column: 1 / -1; position: relative; height: 14px; overflow: hidden; border-radius: 999px; background: repeating-linear-gradient(135deg, rgba(100, 116, 139, .16) 0 6px, rgba(100, 116, 139, .06) 6px 12px); }
    .vg-track-zone__active { position: absolute; top: 0; bottom: 0; min-width: 4px; border-radius: 999px; background: var(--track-color); opacity: .8; }
    .vg-track-zone__active--muted { opacity: .18; filter: grayscale(.9); }
    .vg-track-zone__active--isolated { opacity: 1; box-shadow: inset 0 0 0 2px rgba(255, 255, 255, .45); }
    .vg-hidden-audio { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
    .vg-missing { padding: 28px; border: 1px dashed #cbd5e1; border-radius: 13px; background: #fff; color: #64748b; text-align: center; font-size: 14px; }
    .vg-timeline { padding: 20px; }
    .vg-timeline__top { display: flex; align-items: flex-end; justify-content: space-between; gap: 18px; margin-bottom: 18px; }
    .vg-timeline__title { color: #111827; font-size: 20px; line-height: 1.2; font-weight: 850; }
    .vg-timeline__range { color: #64748b; font-size: 13px; }
    .vg-timebar { position: relative; height: 78px; border-radius: 12px; background: linear-gradient(90deg, #eef2ff, #ecfeff); border: 1px solid #dbeafe; }
    .vg-timebar::before { content: ""; position: absolute; left: 16px; right: 16px; top: 38px; height: 2px; background: #94a3b8; }
    .vg-report-marker { position: absolute; top: 8px; bottom: 8px; width: 2px; background: var(--vg-danger); transform: translateX(-50%); }
    .vg-report-marker span { position: absolute; top: 0; max-width: 150px; padding: 4px 7px; border-radius: 8px; background: #fee2e2; color: #991b1b; font-size: 11px; font-weight: 850; white-space: nowrap; }
    .vg-report-marker--end span { right: 8px; }
    .vg-report-marker:not(.vg-report-marker--end) span { left: 8px; }
    .vg-event-pin { position: absolute; top: 31px; width: 16px; height: 16px; transform: translateX(-50%); border: 3px solid #fff; border-radius: 999px; background: #64748b; box-shadow: 0 2px 8px rgba(15, 23, 42, .25); }
    .vg-event-pin--emerald { background: #10b981; }
    .vg-event-pin--amber { background: #f59e0b; }
    .vg-event-pin--sky { background: #0ea5e9; }
    .vg-event-pin--slate { background: #64748b; }
    .vg-event-pin--live { background: #dc2626; }
    .vg-tooltip { position: absolute; left: 50%; bottom: 25px; z-index: 5; width: max-content; max-width: 260px; padding: 9px 10px; border-radius: 10px; background: #111827; color: #fff; font-size: 12px; line-height: 1.35; opacity: 0; pointer-events: none; transform: translateX(-50%) translateY(4px); transition: opacity .15s ease, transform .15s ease; box-shadow: 0 12px 28px rgba(15, 23, 42, .25); }
    .vg-tooltip strong { display: block; font-size: 12px; }
    .vg-tooltip span { display: block; margin-top: 2px; color: #cbd5e1; }
    .vg-event-pin:hover .vg-tooltip, .vg-lane-event:hover .vg-tooltip { opacity: 1; transform: translateX(-50%) translateY(0); }
    .vg-lanes { display: grid; gap: 14px; margin-top: 18px; }
    .vg-lane { display: grid; grid-template-columns: 180px minmax(0, 1fr); gap: 16px; align-items: center; }
    .vg-lane__name { overflow: hidden; color: #111827; font-size: 13px; font-weight: 850; text-overflow: ellipsis; white-space: nowrap; }
    .vg-lane__rail { position: relative; min-height: 46px; border-radius: 10px; background: #f8fafc; border: 1px solid #e5e7eb; }
    .vg-lane__rail::before { content: ""; position: absolute; left: 10px; right: 10px; top: 22px; height: 1px; background: #cbd5e1; }
    .vg-lane-event { position: absolute; top: 9px; transform: translateX(-50%); display: grid; place-items: center; min-width: 28px; height: 28px; padding: 0 7px; border-radius: 999px; border: 2px solid #fff; background: #64748b; color: #fff; font-size: 10px; font-weight: 900; box-shadow: 0 2px 7px rgba(15, 23, 42, .18); }
    .vg-lane-event--emerald { background: #10b981; }
    .vg-lane-event--amber { background: #f59e0b; }
    .vg-lane-event--sky { background: #0ea5e9; }
    .vg-lane-event--slate { background: #64748b; }
    .vg-lane-event--live { background: #dc2626; }
    @media (max-width: 980px) { .vg-review__transport { display: flex; flex-wrap: wrap; width: 100%; } .vg-review__transport .vg-btn { flex: 1 1 140px; } .vg-lane { grid-template-columns: 1fr; gap: 8px; } }
    .dark .vg-review { border-color: #334155; background: #020617; box-shadow: 0 1px 2px rgba(0, 0, 0, .35); }
    .dark .vg-review__body { background: #0f172a; }
    .dark .vg-main-player, .dark .vg-tracks, .dark .vg-timeline { border-color: #334155; background: #020617; }
    .dark .vg-main-player__label, .dark .vg-main-player__hint, .dark .vg-master__state, .dark .vg-speakers__label, .dark .vg-track-time, .dark .vg-timeline__range, .dark .vg-master-legend { color: #94a3b8; }
    .dark .vg-main-player__name, .dark .vg-tracks__head, .dark .vg-track-name, .dark .vg-timeline__title, .dark .vg-lane__name, .dark .vg-master__time { color: #f8fafc; }
    .dark .vg-master { border-color: #334155; background: #0f172a; }
    .dark .vg-master-map { border-color: #1e3a8a; background: #111827; }
    .dark .vg-master-map::before { background-image: linear-gradient(90deg, rgba(148, 163, 184, .12) 1px, transparent 1px); }
    .dark .vg-silent-zone { background: repeating-linear-gradient(135deg, rgba(148, 163, 184, .26) 0 7px, rgba(30, 41, 59, .55) 7px 14px); }
    .dark .vg-playhead, .dark .vg-playhead::before { background: #f8fafc; }
    .dark .vg-speaker-chip { background: rgba(20, 184, 166, .18); color: #99f6e4; }
    .dark .vg-speaker-chip--empty { background: #1e293b; color: #94a3b8; }
    .dark .vg-tracks__head, .dark .vg-track-row { border-color: #1e293b; }
    .dark .vg-track-row--solo { background: rgba(8, 145, 178, .16); }
    .dark .vg-track-row--speaking { background: rgba(20, 184, 166, .12); }
    .dark .vg-track-avatar { background: rgba(20, 184, 166, .18); color: #99f6e4; }
    .dark .vg-track-zone { background: repeating-linear-gradient(135deg, rgba(148, 163, 184, .18) 0 6px, rgba(30, 41, 59, .55) 6px 12px); }
    .dark .vg-missing { border-color: #475569; background: #0f172a; color: #94a3b8; }
    .dark .vg-timebar { border-color: #1e3a8a; background: linear-gradient(90deg, #111827, #0f172a); }
    .dark .vg-timebar::before, .dark .vg-lane__rail::before { background: #475569; }
    .dark .vg-report-marker span { background: rgba(127, 29, 29, .9); color: #fecaca; }
    .dark .vg-event-pin, .dark .vg-lane-event { border-color: #020617; }
    .dark .vg-lane__rail { border-color: #334155; background: #0f172a; }
    .dark .vg-tooltip { background: #f8fafc; color: #020617; box-shadow: 0 12px 28px rgba(0, 0, 0, .45); }
    .dark .vg-tooltip span { color: #475569; }
    .dark .vg-btn { border-color: #475569; background: #1e293b; color: #f8fafc; box-shadow: none; }
    .dark .vg-btn:hover { background: #334155; }
    .dark .vg-btn--primary { border-color: #14b8a6; background: #14b8a6; color: #042f2e; }
    .dark .vg-btn--active { border-color: #14b8a6; background: rgba(20, 184, 166, .18); color: #99f6e4; }
</style>

<section
    class="vg-review"
    x-data="{
        tracks: @js($tracks),
        events: @js($events),
        eventUsers: @js($eventUsers),
        silentZones: @js($silentZones->values()),
        trackSegments: @js($trackSegments),
        muted: {},
        solo: null,
        syncTime: 0,
        volume: 1,
        playing: false,
        raf: null,
        lastTick: null,
        playable() { return this.tracks.filter((track) => track.available) },
        audio(id) { return this.$root.querySelector('[data-audio-id=\'' + id + '\']') },
        isAudible(id) { return !this.muted[id] && (!this.solo || this.solo === id) },
        activeSpeakers() { return this.playable().filter((track) => this.trackIsActive(track) && this.isAudible(track.id)) },
        totalDuration() { return Math.max(1, ...this.playable().map((track) => Number(track.endOffset) || Number(track.duration) || 0)) },
        playheadOffset() { return Math.max(0, Math.min(100, this.syncTime / this.totalDuration() * 100)) },
        segmentFor(id) { return this.trackSegments.find((segment) => segment.id === id) || { start: 0, width: 0 } },
        zoneClass(id) { return { 'vg-talk-zone--dimmed': this.muted[id] || (this.solo && this.solo !== id), 'vg-talk-zone--isolated': this.solo === id } },
        trackZoneClass(id) { return { 'vg-track-zone__active--muted': this.muted[id] || (this.solo && this.solo !== id), 'vg-track-zone__active--isolated': this.solo === id } },
        formatted(seconds) { const safe = Math.max(0, Math.floor(Number(seconds) || 0)); return String(Math.floor(safe / 60)).padStart(2, '0') + ':' + String(safe % 60).padStart(2, '0') },
        initPlayers() { this.$nextTick(() => this.applyMix()) },
        setAllTime(value) { this.syncTime = Number(value) || 0; this.syncTracks(true) },
        setVolume(value) { this.volume = Number(value); this.playable().forEach((track) => { const audio = this.audio(track.id); if (audio) audio.volume = this.volume }); },
        applyMix() { this.playable().forEach((track) => { const audio = this.audio(track.id); if (audio) { audio.muted = !this.isAudible(track.id); audio.volume = this.volume } }); this.syncTracks(false) },
        trackLocalTime(track) { return this.syncTime - (Number(track.startOffset) || 0) },
        trackIsActive(track) { const local = this.trackLocalTime(track); return local >= 0 && local <= (Number(track.duration) || 0) },
        syncTracks(force) {
            this.playable().forEach((track) => {
                const audio = this.audio(track.id);
                if (!audio) return;
                const local = this.trackLocalTime(track);
                const audible = this.isAudible(track.id);
                audio.volume = this.volume;
                audio.muted = !audible;

                if (!this.trackIsActive(track) || !audible) {
                    audio.pause();
                    if (force && local < 0) audio.currentTime = 0;
                    return;
                }

                const targetTime = Math.max(0, Math.min(local, audio.duration || Number(track.duration) || local));
                if (force || Math.abs(audio.currentTime - targetTime) > 0.35) audio.currentTime = targetTime;
                if (this.playing && audio.paused) audio.play().catch(() => {});
            });
        },
        tick(now) {
            if (!this.playing) return;
            if (this.lastTick !== null) this.syncTime += (now - this.lastTick) / 1000;
            this.lastTick = now;
            if (this.syncTime >= this.totalDuration()) {
                this.syncTime = this.totalDuration();
                this.pauseAll();
                return;
            }
            this.syncTracks(false);
            this.raf = requestAnimationFrame((timestamp) => this.tick(timestamp));
        },
        playAll() { this.applyMix(); this.playing = true; this.lastTick = null; this.syncTracks(true); if (this.raf) cancelAnimationFrame(this.raf); this.raf = requestAnimationFrame((timestamp) => this.tick(timestamp)) },
        pauseAll() { if (this.raf) cancelAnimationFrame(this.raf); this.raf = null; this.lastTick = null; this.playable().forEach((track) => this.audio(track.id)?.pause()); this.playing = false },
        stopAll() { this.pauseAll(); this.syncTime = 0; this.syncTracks(true) },
        resetMix() { this.solo = null; this.muted = {}; this.applyMix() },
        toggleMute(id) { this.muted[id] = !this.muted[id]; this.applyMix() },
        toggleSolo(id) { this.solo = this.solo === id ? null : id; this.applyMix() },
        isolate(id) { this.solo = id; this.muted = {}; this.applyMix() },
        onTime(id) {},
        eventTone(event) { return event.type === 'screen_share_start' ? 'live' : event.tone },
        shortLabel(event) {
            return { voice_join: 'IN', voice_leave: 'OUT', voice_mute: 'MIC', voice_unmute: 'MIC', voice_deafen: 'SOURD', voice_undeafen: 'SON', screen_share_start: 'LIVE', screen_share_stop: 'STOP' }[event.type] || 'EVT';
        },
    }"
    x-init="initPlayers()"
>
    <div class="vg-review__hero">
        <div class="vg-review__kicker">Revue du signalement</div>
        <h2 class="vg-review__title">Enregistrement et timeline</h2>
        <p class="vg-review__copy">Une seule vue pour réécouter le salon vocal dans son ordre réel, couper des pistes, isoler une personne et replacer les actions au moment exact.</p>
    </div>

    <div class="vg-review__body">
        @if ($clips->isEmpty())
            <div class="vg-missing">Aucun enregistrement disponible pour ce signalement.</div>
        @else
            <div class="vg-mixer">
                <div class="vg-main-player">
                    <div class="vg-main-player__label">Résumé de la conversation</div>
                    <div class="vg-main-player__name">Relecture temporelle du salon vocal</div>
                    <div class="vg-main-player__hint">Le lecteur suit l’horloge réelle de l’enregistrement : chaque piste démarre au moment où elle était active dans le vocal.</div>
                    <div class="vg-master">
                        <div class="vg-master-map" aria-label="Zones de parole et de silence">
                            <template x-for="zone in silentZones" :key="'silent-' + zone.start + '-' + zone.width">
                                <span class="vg-silent-zone" x-bind:style="'left: ' + zone.start + '%; width: ' + zone.width + '%'"></span>
                            </template>
                            <template x-for="segment in trackSegments" :key="'segment-' + segment.id">
                                <span class="vg-talk-zone" x-bind:class="zoneClass(segment.id)" x-bind:style="'left: ' + segment.start + '%; width: ' + segment.width + '%; --track-color: ' + segment.color" x-bind:title="segment.name"></span>
                            </template>
                            <span class="vg-playhead" x-bind:style="'left: ' + playheadOffset() + '%'"></span>
                        </div>
                        <div class="vg-master-legend">
                            <span class="vg-master-legend__item"><span class="vg-master-legend__silent"></span> Silence</span>
                            <template x-for="track in tracks" :key="'legend-' + track.id">
                                <span class="vg-master-legend__item"><span class="vg-master-legend__swatch" x-bind:style="'--track-color: ' + track.color"></span><span x-text="track.name"></span></span>
                            </template>
                        </div>
                        <input class="vg-master__range" type="range" min="0" x-bind:max="totalDuration()" step="0.1" x-model.number="syncTime" x-on:input="setAllTime($event.target.value)" aria-label="Position de lecture">
                        <div class="vg-master__row">
                            <button type="button" x-on:click="playing ? pauseAll() : playAll()" class="vg-btn vg-btn--primary"><span x-text="playing ? 'Pause' : 'Lire le mix'"></span></button>
                            <button type="button" x-on:click="stopAll()" class="vg-btn">Début</button>
                            <span class="vg-master__time" x-text="formatted(syncTime) + ' / ' + formatted(totalDuration())"></span>
                            <input class="vg-master__volume" type="range" min="0" max="1" step="0.01" x-model.number="volume" x-on:input="setVolume($event.target.value)" aria-label="Volume du mix">
                            <span class="vg-master__state" x-text="solo ? 'Solo actif' : 'Relecture du vocal complet'"></span>
                        </div>
                        <div class="vg-speakers" aria-label="Personnes audibles maintenant">
                            <span class="vg-speakers__label">Parlent maintenant</span>
                            <template x-if="activeSpeakers().length === 0">
                                <span class="vg-speaker-chip vg-speaker-chip--empty">Personne</span>
                            </template>
                            <template x-for="track in activeSpeakers()" :key="'speaker-' + track.id">
                                <span class="vg-speaker-chip" x-text="track.name"></span>
                            </template>
                        </div>
                    </div>
                    <template x-for="track in tracks" :key="track.id">
                        <audio class="vg-hidden-audio" preload="metadata" x-bind:data-audio-id="track.id" x-bind:src="track.url" x-on:timeupdate="onTime(track.id)"></audio>
                    </template>
                </div>

                <div class="vg-tracks">
                    <div class="vg-tracks__head">Utilisateurs enregistrés</div>
                    <template x-for="track in tracks" :key="track.id">
                        <div class="vg-track-row" x-bind:class="{ 'vg-track-row--solo': solo === track.id, 'vg-track-row--muted': muted[track.id], 'vg-track-row--speaking': trackIsActive(track) && isAudible(track.id) }">
                            <div class="vg-track-person">
                                <span class="vg-track-avatar" x-text="track.initials"></span>
                                <span>
                                    <span class="vg-track-name" x-text="track.name"></span>
                                    <span class="vg-track-time" x-text="(track.from || '?') + ' - ' + (track.until || '?')"></span>
                                </span>
                            </div>
                            <div class="vg-track-actions">
                                <button type="button" x-on:click="toggleMute(track.id)" x-bind:class="muted[track.id] ? 'vg-btn--active' : ''" class="vg-btn"><span x-text="muted[track.id] ? 'Activer' : 'Mute'"></span></button>
                                <button type="button" x-on:click="toggleSolo(track.id)" x-bind:class="solo === track.id ? 'vg-btn--active' : ''" class="vg-btn">Solo</button>
                                <button type="button" x-on:click="isolate(track.id)" class="vg-btn vg-btn--primary">Isoler</button>
                            </div>
                            <div class="vg-track-zone">
                                <span class="vg-track-zone__active" x-bind:class="trackZoneClass(track.id)" x-bind:style="'left: ' + segmentFor(track.id).start + '%; width: ' + segmentFor(track.id).width + '%; --track-color: ' + track.color"></span>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        @endif

        <div class="vg-timeline">
            <div class="vg-timeline__top">
                <div>
                    <div class="vg-timeline__title">Timeline temporelle</div>
                    <div class="vg-timeline__range">{{ $from->format('H:i:s') }} - {{ $until->format('H:i:s') }}</div>
                </div>
            </div>

            @if ($events->isEmpty())
                <div class="vg-missing">Aucun événement vocal trouvé autour de cet enregistrement.</div>
            @else
                <div class="vg-timebar">
                    <div class="vg-report-marker {{ $reportOffset > 82 ? 'vg-report-marker--end' : '' }}" style="left: {{ $reportOffset }}%"><span>Signalement {{ $reportedAtForTimeline->format('H:i:s') }}</span></div>
                    <template x-for="event in events" :key="event.id">
                        <span class="vg-event-pin" x-bind:class="'vg-event-pin--' + eventTone(event)" x-bind:style="'left: ' + event.offset + '%'">
                            <span class="vg-tooltip"><strong x-text="event.label"></strong><span x-text="event.time + ' · ' + event.actor"></span><span x-text="event.channel"></span></span>
                        </span>
                    </template>
                </div>

                <div class="vg-lanes">
                    <template x-for="user in eventUsers" :key="user.id">
                        <div class="vg-lane">
                            <div class="vg-lane__name" x-text="user.name"></div>
                            <div class="vg-lane__rail">
                                <template x-for="event in user.events" :key="event.id">
                                    <span class="vg-lane-event" x-bind:class="'vg-lane-event--' + eventTone(event)" x-bind:style="'left: ' + event.offset + '%'">
                                        <span x-text="shortLabel(event)"></span>
                                        <span class="vg-tooltip"><strong x-text="event.label"></strong><span x-text="event.time + ' · ' + event.actor"></span><span x-text="event.channel"></span></span>
                                    </span>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            @endif
        </div>
    </div>
</section>
