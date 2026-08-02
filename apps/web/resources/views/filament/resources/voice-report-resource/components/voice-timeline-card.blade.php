<article class="vg-timeline-card {{ $toneClass[$event->tone()] ?? $toneClass['slate'] }}">
    <div class="vg-timeline-card__top">
        <div class="vg-timeline-card__event">{{ $event->label() }}</div>
        <time class="vg-timeline-card__time">{{ $event->occurred_at->format('H:i:s') }}</time>
    </div>
    <div class="vg-timeline-card__actor">{{ $event->actorName() }}</div>
    <div class="vg-timeline-card__meta">
        {{ $event->channel?->name ?? 'Salon inconnu' }}
        @if (data_get($event->payload, 'old_channel_id') && data_get($event->payload, 'new_channel_id'))
            <br>De {{ data_get($event->payload, 'old_channel_id') }} vers {{ data_get($event->payload, 'new_channel_id') }}
        @endif
    </div>
</article>
