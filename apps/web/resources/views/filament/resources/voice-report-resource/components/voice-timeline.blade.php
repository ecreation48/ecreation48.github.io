@php
    use App\Models\VoiceEvent;

    $reportedAt = $record->reported_at ?? $record->created_at ?? now();
    $from = $reportedAt->copy()->subMinutes(30);
    $until = $reportedAt->copy()->addMinutes(10);
    $fromUtc = $from->copy()->utc();
    $untilUtc = $until->copy()->utc();
    $events = VoiceEvent::query()
        ->with(['guild', 'channel'])
        ->where('discord_guild_id', $record->discord_guild_id)
        ->where(function ($query) use ($record) {
            $query->where('discord_channel_id', $record->discord_channel_id)->orWhereNull('discord_channel_id');
        })
        ->where(function ($query) use ($from, $until, $fromUtc, $untilUtc) {
            $query->whereBetween('occurred_at', [$from, $until])
                ->orWhereBetween('occurred_at', [$fromUtc, $untilUtc]);
        })
        ->oldest('occurred_at')
        ->get();
    $byUser = $events->groupBy(fn (VoiceEvent $event): string => $event->actorName());
    $toneClass = [
        'emerald' => 'vg-timeline-card--emerald',
        'amber' => 'vg-timeline-card--amber',
        'sky' => 'vg-timeline-card--sky',
        'slate' => 'vg-timeline-card--slate',
    ];
@endphp

<style>
    .vg-timeline { border: 1px solid #d1d5db; border-radius: 14px; background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, .06); overflow: hidden; }
    .vg-timeline__head { display: flex; justify-content: space-between; gap: 18px; align-items: flex-start; flex-wrap: wrap; padding: 22px 24px; border-bottom: 1px solid #e5e7eb; background: #f8fafc; }
    .vg-timeline__kicker { color: #4f46e5; font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
    .vg-timeline__title { margin: 4px 0 0; color: #111827; font-size: 23px; line-height: 1.15; font-weight: 800; }
    .vg-timeline__copy { max-width: 760px; margin: 9px 0 0; color: #64748b; font-size: 14px; }
    .vg-timeline__switch { display: inline-flex; gap: 4px; padding: 4px; border: 1px solid #d1d5db; border-radius: 12px; background: #fff; }
    .vg-timeline__switch button { border: 0; border-radius: 9px; padding: 9px 13px; background: transparent; color: #475569; font-size: 13px; font-weight: 800; cursor: pointer; }
    .vg-timeline__switch button.is-active { background: #111827; color: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, .15); }
    .vg-timeline__stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; padding: 16px 24px; border-bottom: 1px solid #e5e7eb; background: #fff; }
    .vg-timeline-stat { min-width: 0; border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px 14px; background: #fff; }
    .vg-timeline-stat span { display: block; color: #64748b; font-size: 11px; font-weight: 800; text-transform: uppercase; }
    .vg-timeline-stat strong { display: block; margin-top: 4px; color: #111827; font-size: 24px; line-height: 1; }
    .vg-timeline__body { padding: 22px 24px 24px; background: #fff; }
    .vg-timeline__mode { display: none; }
    .vg-timeline[data-mode="grouped"] .vg-timeline__mode--grouped, .vg-timeline[data-mode="users"] .vg-timeline__mode--users { display: block; }
    .vg-timeline-lane { display: grid; grid-template-columns: 120px minmax(0, 1fr); gap: 18px; padding: 0 0 18px; }
    .vg-timeline-lane + .vg-timeline-lane { border-top: 1px solid #eef2f7; padding-top: 18px; }
    .vg-timeline-lane__label { position: sticky; top: 0; align-self: start; color: #111827; font-size: 13px; font-weight: 800; }
    .vg-timeline-lane__sub { margin-top: 3px; color: #64748b; font-size: 12px; font-weight: 500; }
    .vg-timeline-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 12px; }
    .vg-timeline-card { border: 1px solid #e2e8f0; border-radius: 13px; padding: 14px; background: #f8fafc; box-shadow: inset 4px 0 0 #94a3b8; }
    .vg-timeline-card--emerald { background: #ecfdf5; border-color: #bbf7d0; box-shadow: inset 4px 0 0 #10b981; }
    .vg-timeline-card--amber { background: #fffbeb; border-color: #fde68a; box-shadow: inset 4px 0 0 #f59e0b; }
    .vg-timeline-card--sky { background: #eff6ff; border-color: #bfdbfe; box-shadow: inset 4px 0 0 #0ea5e9; }
    .vg-timeline-card--slate { background: #f8fafc; border-color: #e2e8f0; box-shadow: inset 4px 0 0 #64748b; }
    .vg-timeline-card__top { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start; }
    .vg-timeline-card__event { color: #111827; font-size: 14px; font-weight: 800; }
    .vg-timeline-card__time { color: #64748b; font-size: 12px; font-weight: 800; }
    .vg-timeline-card__actor { margin-top: 5px; color: #334155; font-size: 13px; font-weight: 700; }
    .vg-timeline-card__meta { margin-top: 8px; color: #64748b; font-size: 12px; line-height: 1.45; }
    .vg-timeline__empty { display: grid; place-items: center; min-height: 180px; border: 1px dashed #cbd5e1; border-radius: 13px; background: #f8fafc; color: #64748b; text-align: center; font-size: 14px; }
    @media (max-width: 760px) { .vg-timeline__stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } .vg-timeline-lane { grid-template-columns: 1fr; gap: 10px; } }
    @media (prefers-color-scheme: dark) {
        .vg-timeline { border-color: #334155; background: #020617; }
        .vg-timeline__head, .vg-timeline__body, .vg-timeline__stats { border-color: #1e293b; background: #020617; }
        .vg-timeline__title, .vg-timeline-stat strong, .vg-timeline-lane__label, .vg-timeline-card__event { color: #f8fafc; }
        .vg-timeline__copy, .vg-timeline-stat span, .vg-timeline-lane__sub, .vg-timeline-card__time, .vg-timeline-card__meta { color: #94a3b8; }
        .vg-timeline-stat, .vg-timeline__switch { border-color: #334155; background: #0f172a; }
        .vg-timeline-card { border-color: #334155; background: #0f172a; }
        .vg-timeline-card__actor { color: #cbd5e1; }
        .vg-timeline__empty { border-color: #334155; background: #0f172a; color: #94a3b8; }
    }
</style>

<section class="vg-timeline" x-data="{ mode: 'grouped' }" x-bind:data-mode="mode">
    <div class="vg-timeline__head">
        <div>
            <div class="vg-timeline__kicker">Contexte vocal</div>
            <h2 class="vg-timeline__title">Timeline du signalement</h2>
            <p class="vg-timeline__copy">Fenêtre analysée : {{ $from->format('H:i') }} - {{ $until->format('H:i') }} autour du signalement. Les événements sont limités au salon concerné.</p>
        </div>
        <div class="vg-timeline__switch" aria-label="Mode de timeline">
            <button type="button" x-on:click="mode = 'grouped'" x-bind:class="{ 'is-active': mode === 'grouped' }">Vue groupée</button>
            <button type="button" x-on:click="mode = 'users'" x-bind:class="{ 'is-active': mode === 'users' }">Par utilisateur</button>
        </div>
    </div>

    <div class="vg-timeline__stats">
        <div class="vg-timeline-stat"><span>Événements</span><strong>{{ $events->count() }}</strong></div>
        <div class="vg-timeline-stat"><span>Utilisateurs</span><strong>{{ $events->pluck('discord_user_id')->filter()->unique()->count() }}</strong></div>
        <div class="vg-timeline-stat"><span>Micros</span><strong>{{ $events->whereIn('type', ['voice_mute', 'voice_unmute'])->count() }}</strong></div>
        <div class="vg-timeline-stat"><span>Partages</span><strong>{{ $events->whereIn('type', ['screen_share_start', 'screen_share_stop'])->count() }}</strong></div>
    </div>

    <div class="vg-timeline__body">
        @if ($events->isEmpty())
            <div class="vg-timeline__empty">Aucun événement vocal trouvé autour de ce signalement.</div>
        @else
            <div class="vg-timeline__mode vg-timeline__mode--grouped">
                @foreach ($events->groupBy(fn (VoiceEvent $event) => $event->occurred_at->format('H:i')) as $label => $items)
                    <div class="vg-timeline-lane">
                        <div>
                            <div class="vg-timeline-lane__label">{{ $label }}</div>
                            <div class="vg-timeline-lane__sub">{{ $items->count() }} événement(s)</div>
                        </div>
                        <div class="vg-timeline-grid">
                            @foreach ($items as $event)
                                @include('filament.resources.voice-report-resource.components.voice-timeline-card', ['event' => $event, 'toneClass' => $toneClass])
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="vg-timeline__mode vg-timeline__mode--users">
                @foreach ($byUser as $label => $items)
                    <div class="vg-timeline-lane">
                        <div>
                            <div class="vg-timeline-lane__label">{{ $label }}</div>
                            <div class="vg-timeline-lane__sub">{{ $items->count() }} événement(s)</div>
                        </div>
                        <div class="vg-timeline-grid">
                            @foreach ($items as $event)
                                @include('filament.resources.voice-report-resource.components.voice-timeline-card', ['event' => $event, 'toneClass' => $toneClass])
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>
