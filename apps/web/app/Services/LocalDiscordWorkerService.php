<?php

namespace App\Services;

use App\Models\SystemLog;
use App\Support\GlobalSettings;
use RuntimeException;
use Symfony\Component\Process\Process;

class LocalDiscordWorkerService
{
    public function isRunning(): bool
    {
        $pid = $this->pid();

        if ($pid === null) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        $process = new Process(['ps', '-p', (string) $pid]);
        $process->run();

        return $process->isSuccessful();
    }

    public function pid(): ?int
    {
        $pidFile = $this->pidFile();

        if (! is_file($pidFile)) {
            return null;
        }

        $pid = (int) trim((string) file_get_contents($pidFile));

        return $pid > 0 ? $pid : null;
    }

    public function start(): int
    {
        if ($this->isRunning()) {
            return $this->pid() ?? 0;
        }

        $this->ensureReady();
        $this->ensureStorage();

        $pidFile = $this->pidFile();
        $logFile = $this->logFile();
        $command = $this->shellCommand($pidFile, $logFile);
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(10);
        $process->mustRun();

        usleep(300_000);

        $pid = $this->pid();

        if ($pid === null || ! $this->isRunning()) {
            throw new RuntimeException('Le worker Discord n’a pas démarré. Consulte le fichier de logs.');
        }

        $this->log('info', 'worker_process_started', 'Worker Discord démarré', ['pid' => $pid, 'log_file' => $logFile]);

        return $pid;
    }

    public function stop(): void
    {
        $pid = $this->pid();

        if ($pid === null) {
            return;
        }

        if ($this->isRunning()) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, SIGTERM);
            } else {
                Process::fromShellCommandline('kill '.escapeshellarg((string) $pid))->run();
            }

            usleep(500_000);

            if ($this->isRunning() && function_exists('posix_kill')) {
                @posix_kill($pid, SIGKILL);
            }
        }

        @unlink($this->pidFile());

        $this->log('warning', 'worker_process_stopped', 'Worker Discord stoppé', ['pid' => $pid]);
    }

    public function restart(): int
    {
        $this->stop();

        return $this->start();
    }

    public function tail(int $lines = 120): string
    {
        $logFile = $this->logFile();

        if (! is_file($logFile)) {
            return 'Aucun log worker pour le moment.';
        }

        $process = new Process(['tail', '-n', (string) $lines, $logFile]);
        $process->run();

        return trim($process->getOutput()) ?: 'Le fichier de logs est vide.';
    }

    public function logFile(): string
    {
        return storage_path('logs/discord-worker.log');
    }

    private function pidFile(): string
    {
        return storage_path('app/discord-worker.pid');
    }

    private function ensureReady(): void
    {
        $path = (string) config('services.worker.path');
        $token = (string) config('services.worker.token');

        if (! is_dir($path)) {
            throw new RuntimeException('Le dossier du worker Discord est introuvable : '.$path);
        }

        if (strlen($token) < 24) {
            throw new RuntimeException('WORKER_SERVICE_TOKEN doit contenir au moins 24 caractères.');
        }
    }

    private function ensureStorage(): void
    {
        if (! is_dir(dirname($this->pidFile()))) {
            mkdir(dirname($this->pidFile()), 0775, true);
        }

        if (! is_dir(dirname($this->logFile()))) {
            mkdir(dirname($this->logFile()), 0775, true);
        }
    }

    private function shellCommand(string $pidFile, string $logFile): string
    {
        $path = (string) config('services.worker.path');
        $workerCommand = (string) config('services.worker.command');
        $settings = app(GlobalSettings::class);
        $env = [
            'PATH' => '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin',
            'INTERNAL_API_URL' => $this->internalApiUrl(),
            'WORKER_SERVICE_TOKEN' => (string) config('services.worker.token'),
            'WORKER_ID' => (string) config('services.worker.id'),
            'REDIS_URL' => (string) config('services.worker.redis_url'),
            'LIVE_AUDIO_PORT' => (string) config('services.worker.live_audio_port', 8787),
            'DISCORD_CHANNEL_SYNC_INTERVAL_MS' => (string) $settings->get('defaults.channel_sync_interval_ms', 600000),
            'OPENAI_API_KEY' => (string) config('services.worker.openai_api_key', ''),
        ] + $settings->workerEnvironment();

        $exports = collect($env)
            ->map(fn (string $value, string $key): string => $key.'='.escapeshellarg($value))
            ->implode(' ');

        return sprintf(
            'cd %s && nohup env %s %s >> %s 2>&1 & echo $! > %s',
            escapeshellarg($path),
            $exports,
            $workerCommand,
            escapeshellarg($logFile),
            escapeshellarg($pidFile),
        );
    }

    private function internalApiUrl(): string
    {
        return (string) config('services.worker.local_internal_api_url');
    }

    private function log(string $level, string $event, string $message, array $context = []): void
    {
        SystemLog::query()->create([
            'level' => $level,
            'source' => 'discord-worker',
            'event' => $event,
            'message' => $message,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
