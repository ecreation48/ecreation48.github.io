<div class="space-y-4">
    <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm shadow-sm dark:border-gray-800 dark:bg-gray-950">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="font-semibold text-gray-950 dark:text-white">
                    Worker Discord local
                </div>
                <div class="mt-1 text-gray-500 dark:text-gray-400">
                    {{ $isRunning ? 'Processus en cours' : 'Processus arrêté' }}
                    @if ($pid)
                        · PID {{ $pid }}
                    @endif
                </div>
            </div>

            <span @class([
                'inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset',
                'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30' => $isRunning,
                'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-300 dark:ring-gray-500/30' => ! $isRunning,
            ])>
                {{ $isRunning ? 'Actif' : 'Inactif' }}
            </span>
        </div>

        <div class="mt-3 break-all text-xs text-gray-500 dark:text-gray-400">
            Logs : {{ $logFile }}
        </div>
    </div>

    <pre class="max-h-[32rem] overflow-auto rounded-lg bg-gray-950 p-4 text-xs leading-5 text-gray-100 shadow-inner">{{ $logs }}</pre>
</div>
