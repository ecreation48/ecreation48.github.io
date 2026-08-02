@php($record = $getRecord())
<div class="mx-auto w-full max-w-4xl rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="text-base font-semibold text-gray-950 dark:text-white">{{ $record->displayName() }}</div>
        </div>
        <div class="flex flex-wrap gap-2 text-xs font-medium">
            <span class="rounded-md bg-gray-100 px-2.5 py-1.5 text-gray-700 dark:bg-gray-800 dark:text-gray-300">WAV</span>
            <span class="rounded-md bg-gray-100 px-2.5 py-1.5 text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ $record->duration_seconds }} s</span>
            <span class="rounded-md bg-gray-100 px-2.5 py-1.5 text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ $record->humanSize() }}</span>
        </div>
    </div>
    @if ($record->mime_type === 'audio/wav' && $record->resolvedStoragePath())
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-950">
            <audio controls preload="metadata" class="w-full">
                <source src="{{ route('admin.audio-clips.stream', $record) }}" type="{{ $record->mime_type }}">
            </audio>
        </div>
    @else
        <div class="rounded-lg bg-danger-50 px-4 py-3 text-sm text-danger-700 dark:bg-danger-950 dark:text-danger-300">Lecture indisponible</div>
    @endif
    <div class="mt-4 grid gap-3 text-xs text-gray-500 dark:text-gray-400 sm:grid-cols-3">
        <div>
            <div class="font-medium text-gray-700 dark:text-gray-300">Début</div>
            <div>{{ $record->captured_from?->format('H:i:s') }}</div>
        </div>
        <div>
            <div class="font-medium text-gray-700 dark:text-gray-300">Fin</div>
            <div>{{ $record->captured_until?->format('H:i:s') }}</div>
        </div>
        <div>
            <div class="font-medium text-gray-700 dark:text-gray-300">Statut</div>
            <div>{{ match($record->status) {'captured' => 'Capturé', 'processing' => 'Traitement', 'ready' => 'Prêt', 'failed' => 'Échec', 'deleted' => 'Supprimé', default => $record->status} }}</div>
        </div>
    </div>
</div>
