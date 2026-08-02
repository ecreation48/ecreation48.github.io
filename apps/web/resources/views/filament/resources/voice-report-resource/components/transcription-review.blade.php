@php
    $transcripts = $record->transcripts()->with(['segments', 'audioClip'])->latest()->get();
    $statusLabels = [
        'pending' => 'En attente',
        'processing' => 'En cours',
        'completed' => 'Terminée',
        'failed' => 'Échec',
        'skipped' => 'Non configurée',
    ];
    $statusTones = [
        'pending' => 'waiting',
        'processing' => 'running',
        'completed' => 'ready',
        'failed' => 'failed',
        'skipped' => 'muted',
    ];
@endphp

<style>
    .vg-transcript { overflow: hidden; border: 1px solid #d1d5db; border-radius: 14px; background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, .06); }
    .vg-transcript__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 20px 22px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(180deg, #fff, #f8fafc); }
    .vg-transcript__title { margin: 0; color: #111827; font-size: 18px; line-height: 1.2; font-weight: 850; }
    .vg-transcript__copy { margin: 6px 0 0; max-width: 760px; color: #64748b; font-size: 13px; line-height: 1.45; }
    .vg-transcript__count { display: inline-flex; align-items: center; min-height: 28px; padding: 5px 9px; border: 1px solid #e5e7eb; border-radius: 999px; background: #fff; color: #475569; font-size: 12px; font-weight: 800; white-space: nowrap; }
    .vg-transcript__body { display: grid; gap: 14px; padding: 18px; background: #f8fafc; }
    .vg-transcript__empty { display: grid; place-items: center; min-height: 92px; border: 1px dashed #cbd5e1; border-radius: 12px; background: #fff; color: #64748b; font-size: 14px; text-align: center; }
    .vg-transcript-card { overflow: hidden; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; }
    .vg-transcript-card__meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 13px 15px; border-bottom: 1px solid #f1f5f9; background: #fff; }
    .vg-transcript-pill { display: inline-flex; align-items: center; gap: 6px; min-height: 26px; padding: 4px 8px; border-radius: 999px; background: #f1f5f9; color: #475569; font-size: 12px; font-weight: 800; }
    .vg-transcript-pill::before { content: ""; width: 7px; height: 7px; border-radius: 999px; background: #94a3b8; }
    .vg-transcript-pill--ready { background: #dcfce7; color: #166534; }
    .vg-transcript-pill--ready::before { background: #22c55e; }
    .vg-transcript-pill--running { background: #dbeafe; color: #1d4ed8; }
    .vg-transcript-pill--running::before { background: #3b82f6; }
    .vg-transcript-pill--waiting { background: #fef3c7; color: #92400e; }
    .vg-transcript-pill--waiting::before { background: #f59e0b; }
    .vg-transcript-pill--failed { background: #fee2e2; color: #991b1b; }
    .vg-transcript-pill--failed::before { background: #ef4444; }
    .vg-transcript-meta { color: #64748b; font-size: 12px; font-weight: 700; }
    .vg-transcript-card__content { padding: 15px; }
    .vg-transcript-text { margin: 0; color: #1f2937; font-size: 14px; line-height: 1.7; white-space: pre-wrap; }
    .vg-transcript-message { margin: 0; color: #64748b; font-size: 14px; line-height: 1.6; }
    .vg-transcript-message--failed { color: #b91c1c; }
    .vg-transcript-segments { overflow: hidden; margin-top: 14px; border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; }
    .vg-transcript-segment { display: grid; grid-template-columns: 92px 1fr; gap: 10px; padding: 10px 12px; border-top: 1px solid #f1f5f9; }
    .vg-transcript-segment:first-child { border-top: 0; }
    .vg-transcript-segment__time { color: #64748b; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 12px; font-weight: 800; }
    .vg-transcript-segment__text { color: #334155; font-size: 14px; line-height: 1.45; }

    :is(.dark .vg-transcript) { border-color: #334155; background: #020617; box-shadow: none; }
    :is(.dark .vg-transcript__head) { border-color: #1e293b; background: linear-gradient(180deg, #0f172a, #020617); }
    :is(.dark .vg-transcript__title) { color: #f8fafc; }
    :is(.dark .vg-transcript__copy, .dark .vg-transcript-meta, .dark .vg-transcript-segment__time) { color: #94a3b8; }
    :is(.dark .vg-transcript__count, .dark .vg-transcript-card, .dark .vg-transcript-card__meta, .dark .vg-transcript-segments) { border-color: #334155; background: #0f172a; }
    :is(.dark .vg-transcript__body) { background: #020617; }
    :is(.dark .vg-transcript__empty) { border-color: #334155; background: #0f172a; color: #94a3b8; }
    :is(.dark .vg-transcript-card__meta, .dark .vg-transcript-segment) { border-color: #1e293b; }
    :is(.dark .vg-transcript-text, .dark .vg-transcript-segment__text) { color: #e2e8f0; }
    :is(.dark .vg-transcript-message) { color: #94a3b8; }
    :is(.dark .vg-transcript-message--failed) { color: #fca5a5; }
    :is(.dark .vg-transcript-pill) { background: #1e293b; color: #cbd5e1; }
    :is(.dark .vg-transcript-pill--ready) { background: rgba(34, 197, 94, .16); color: #86efac; }
    :is(.dark .vg-transcript-pill--running) { background: rgba(59, 130, 246, .16); color: #93c5fd; }
    :is(.dark .vg-transcript-pill--waiting) { background: rgba(245, 158, 11, .16); color: #fcd34d; }
    :is(.dark .vg-transcript-pill--failed) { background: rgba(239, 68, 68, .16); color: #fca5a5; }

    @media (prefers-color-scheme: dark) {
        html:not(.light) .vg-transcript { border-color: #334155; background: #020617; box-shadow: none; }
        html:not(.light) .vg-transcript__head { border-color: #1e293b; background: linear-gradient(180deg, #0f172a, #020617); }
        html:not(.light) .vg-transcript__title { color: #f8fafc; }
        html:not(.light) .vg-transcript__copy, html:not(.light) .vg-transcript-meta, html:not(.light) .vg-transcript-segment__time { color: #94a3b8; }
        html:not(.light) .vg-transcript__count, html:not(.light) .vg-transcript-card, html:not(.light) .vg-transcript-card__meta, html:not(.light) .vg-transcript-segments { border-color: #334155; background: #0f172a; }
        html:not(.light) .vg-transcript__body { background: #020617; }
        html:not(.light) .vg-transcript__empty { border-color: #334155; background: #0f172a; color: #94a3b8; }
        html:not(.light) .vg-transcript-card__meta, html:not(.light) .vg-transcript-segment { border-color: #1e293b; }
        html:not(.light) .vg-transcript-text, html:not(.light) .vg-transcript-segment__text { color: #e2e8f0; }
        html:not(.light) .vg-transcript-message { color: #94a3b8; }
        html:not(.light) .vg-transcript-message--failed { color: #fca5a5; }
        html:not(.light) .vg-transcript-pill { background: #1e293b; color: #cbd5e1; }
        html:not(.light) .vg-transcript-pill--ready { background: rgba(34, 197, 94, .16); color: #86efac; }
        html:not(.light) .vg-transcript-pill--running { background: rgba(59, 130, 246, .16); color: #93c5fd; }
        html:not(.light) .vg-transcript-pill--waiting { background: rgba(245, 158, 11, .16); color: #fcd34d; }
        html:not(.light) .vg-transcript-pill--failed { background: rgba(239, 68, 68, .16); color: #fca5a5; }
    }

    @media (max-width: 640px) {
        .vg-transcript__head { display: grid; }
        .vg-transcript__count { justify-self: start; }
        .vg-transcript-segment { grid-template-columns: 1fr; }
    }
</style>

<section class="vg-transcript">
    <div class="vg-transcript__head">
        <div>
            <h2 class="vg-transcript__title">Transcription</h2>
            <p class="vg-transcript__copy">Texte généré uniquement à partir des extraits liés au signalement.</p>
        </div>

        <span class="vg-transcript__count">{{ $transcripts->count() }} résultat(s)</span>
    </div>

    <div class="vg-transcript__body">
        @if ($transcripts->isEmpty())
            <div class="vg-transcript__empty">Aucune transcription disponible pour ce signalement.</div>
        @else
            @foreach ($transcripts as $transcript)
                <article class="vg-transcript-card">
                    <div class="vg-transcript-card__meta">
                        <span class="vg-transcript-pill vg-transcript-pill--{{ $statusTones[$transcript->status] ?? 'muted' }}">
                            {{ $statusLabels[$transcript->status] ?? $transcript->status }}
                        </span>
                        @if ($transcript->audioClip)
                            <span class="vg-transcript-meta">{{ $transcript->audioClip->displayName() }}</span>
                        @endif
                        @if ($transcript->language)
                            <span class="vg-transcript-meta">Langue : {{ strtoupper($transcript->language) }}</span>
                        @endif
                        @if ($transcript->engine)
                            <span class="vg-transcript-meta">Moteur : {{ $transcript->engine }}</span>
                        @endif
                    </div>

                    <div class="vg-transcript-card__content">
                        @if ($transcript->status === 'failed')
                            <p class="vg-transcript-message vg-transcript-message--failed">{{ $transcript->error_message ?? 'La transcription a échoué.' }}</p>
                        @elseif ($transcript->status === 'skipped')
                            <p class="vg-transcript-message">{{ $transcript->error_message ?? 'Aucun moteur de transcription n’est configuré.' }}</p>
                        @else
                            <p class="vg-transcript-text">{{ $transcript->text ?: 'Aucun texte reconnu.' }}</p>

                            @if ($transcript->segments->isNotEmpty())
                                <div class="vg-transcript-segments">
                                    @foreach ($transcript->segments as $segment)
                                        <div class="vg-transcript-segment">
                                            <span class="vg-transcript-segment__time">{{ number_format($segment->start_seconds, 1, ',', ' ') }}s</span>
                                            <span class="vg-transcript-segment__text">{{ $segment->text }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    </div>
                </article>
            @endforeach
        @endif
    </div>
</section>
