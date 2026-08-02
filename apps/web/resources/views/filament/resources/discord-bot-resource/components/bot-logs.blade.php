@php
    use App\Models\SystemLog;

    $logs = SystemLog::query()
        ->where('source', 'discord-bot')
        ->where('context->bot_id', $bot->id)
        ->latest('occurred_at')
        ->limit(80)
        ->get();

    $levelClasses = [
        'error' => 'vg-bot-log__level--error',
        'warning' => 'vg-bot-log__level--warning',
        'info' => 'vg-bot-log__level--info',
    ];
@endphp

<style>
    .vg-bot-logs { display: grid; gap: 10px; max-height: 520px; overflow: auto; padding-right: 4px; }
    .vg-bot-log { display: grid; gap: 6px; padding: 12px; border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; }
    .vg-bot-log__top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .vg-bot-log__meta { display: flex; align-items: center; gap: 8px; min-width: 0; }
    .vg-bot-log__level { padding: 2px 7px; border-radius: 999px; background: #e5e7eb; color: #374151; font-size: 11px; font-weight: 800; text-transform: uppercase; }
    .vg-bot-log__level--info { background: #dbeafe; color: #1d4ed8; }
    .vg-bot-log__level--warning { background: #fef3c7; color: #92400e; }
    .vg-bot-log__level--error { background: #fee2e2; color: #b91c1c; }
    .vg-bot-log__event { overflow: hidden; color: #111827; font-size: 13px; font-weight: 800; text-overflow: ellipsis; white-space: nowrap; }
    .vg-bot-log__time { flex: 0 0 auto; color: #64748b; font-size: 12px; font-weight: 700; }
    .vg-bot-log__message { color: #475569; font-size: 13px; line-height: 1.45; }
    .vg-bot-log__empty { padding: 28px; border: 1px dashed #cbd5e1; border-radius: 12px; color: #64748b; text-align: center; }
    .dark .vg-bot-log { border-color: #334155; background: #020617; }
    .dark .vg-bot-log__event { color: #f8fafc; }
    .dark .vg-bot-log__time, .dark .vg-bot-log__message, .dark .vg-bot-log__empty { color: #94a3b8; }
    .dark .vg-bot-log__empty { border-color: #475569; }
</style>

@if ($logs->isEmpty())
    <div class="vg-bot-log__empty">Aucun log disponible pour ce bot.</div>
@else
    <div class="vg-bot-logs">
        @foreach ($logs as $log)
            <article class="vg-bot-log">
                <div class="vg-bot-log__top">
                    <div class="vg-bot-log__meta">
                        <span class="vg-bot-log__level {{ $levelClasses[$log->level] ?? '' }}">{{ $log->level }}</span>
                        <span class="vg-bot-log__event">{{ $log->event }}</span>
                    </div>
                    <time class="vg-bot-log__time">{{ $log->occurred_at?->format('d/m H:i:s') }}</time>
                </div>
                <div class="vg-bot-log__message">{{ $log->message }}</div>
                @if (data_get($log->context, 'error'))
                    <div class="vg-bot-log__message">{{ data_get($log->context, 'error') }}</div>
                @endif
            </article>
        @endforeach
    </div>
@endif
