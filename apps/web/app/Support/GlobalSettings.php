<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Throwable;

class GlobalSettings
{
    public const KEY = 'global';

    public function all(): array
    {
        try {
            if (! Schema::hasTable('app_settings')) {
                return $this->defaults();
            }

            $settings = AppSetting::query()->find(self::KEY)?->value ?? [];
        } catch (Throwable) {
            return $this->defaults();
        }

        return array_replace_recursive($this->defaults(), $settings);
    }

    public function save(array $settings): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => array_replace_recursive($this->defaults(), $settings)],
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->all(), $key, $default);
    }

    public function channelDefaults(): array
    {
        $settings = $this->all();

        return [
            'buffer_seconds' => (int) $settings['defaults']['buffer_seconds'],
            'retention_days' => (int) $settings['defaults']['retention_days'],
            'volume_analysis_enabled' => (bool) $settings['defaults']['volume_analysis_enabled'],
            'transcription_enabled' => (bool) $settings['defaults']['transcription_enabled'],
        ];
    }

    public function workerEnvironment(): array
    {
        $settings = $this->all();
        $transcription = $settings['transcription'];

        return [
            'TRANSCRIPTION_PROVIDER' => (string) $transcription['provider'],
            'TRANSCRIPTION_ENGINE' => (string) $transcription['engine'],
            'TRANSCRIPTION_LANGUAGE' => (string) $transcription['language'],
            'TRANSCRIPTION_TIMEOUT_MS' => (string) $transcription['timeout_ms'],
            'TRANSCRIPTION_BATCH_SIZE' => (string) $transcription['batch_size'],
            'TRANSCRIPTION_COMMAND' => (string) $transcription['command'],
            'WHISPER_CPP_BINARY' => (string) $transcription['whisper_cpp_binary'],
            'WHISPER_CPP_MODEL' => (string) $transcription['whisper_cpp_model'],
            'WHISPER_CPP_USE_GPU' => (string) ($transcription['whisper_cpp_use_gpu'] ? 'true' : 'false'),
            'OPENAI_BASE_URL' => (string) $transcription['openai_base_url'],
            'OPENAI_TRANSCRIPTION_MODEL' => (string) $transcription['openai_model'],
        ];
    }

    public function defaults(): array
    {
        return [
            'appearance' => [
                'primary_color' => 'indigo',
                'accent_color' => '#14b8a6',
                'danger_color' => '#dc2626',
            ],
            'defaults' => [
                'buffer_seconds' => 45,
                'retention_days' => 30,
                'volume_analysis_enabled' => false,
                'transcription_enabled' => false,
                'monitor_new_voice_channels' => true,
                'channel_sync_interval_ms' => 600000,
            ],
            'transcription' => [
                'provider' => 'command',
                'engine' => 'whisper.cpp',
                'language' => 'fr',
                'timeout_ms' => 120000,
                'batch_size' => 3,
                'command' => 'node ../../scripts/transcribe-whisper-json.mjs {file}',
                'whisper_cpp_binary' => '../../tools/whisper.cpp/build/bin/whisper-cli',
                'whisper_cpp_model' => '../../tools/whisper.cpp/models/ggml-small.bin',
                'whisper_cpp_use_gpu' => false,
                'openai_base_url' => 'https://api.openai.com/v1',
                'openai_model' => 'whisper-1',
            ],
        ];
    }
}
