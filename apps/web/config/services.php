<?php

return [
    'worker' => [
        'token' => env('WORKER_SERVICE_TOKEN'),
        'internal_api_url' => env('INTERNAL_API_URL', rtrim(env('APP_URL', 'http://127.0.0.1:8001'), '/').'/api/v1/internal'),
        'local_internal_api_url' => env('DISCORD_WORKER_INTERNAL_API_URL', rtrim(env('APP_URL', 'http://127.0.0.1:8001'), '/').'/api/v1/internal'),
        'id' => env('WORKER_ID', 'discord-manager-local'),
        'redis_url' => env('REDIS_URL', 'redis://127.0.0.1:6379'),
        'command' => env('DISCORD_WORKER_COMMAND', '/opt/homebrew/bin/node ../../node_modules/tsx/dist/cli.mjs watch --exclude ../../node_modules --exclude node_modules src/index.ts'),
        'path' => env('DISCORD_WORKER_PATH', base_path('../discord-manager')),
        'live_audio_host' => env('LIVE_AUDIO_HOST', '127.0.0.1'),
        'live_audio_port' => env('LIVE_AUDIO_PORT', 8787),
        'transcription_provider' => env('TRANSCRIPTION_PROVIDER'),
        'transcription_command' => env('TRANSCRIPTION_COMMAND'),
        'transcription_engine' => env('TRANSCRIPTION_ENGINE'),
        'transcription_language' => env('TRANSCRIPTION_LANGUAGE', 'fr'),
        'transcription_timeout_ms' => env('TRANSCRIPTION_TIMEOUT_MS', 120000),
        'openai_api_key' => env('OPENAI_API_KEY'),
        'openai_base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'openai_transcription_model' => env('OPENAI_TRANSCRIPTION_MODEL', 'whisper-1'),
    ],

    'authentik' => [
        'enabled' => env('AUTHENTIK_SSO_ENABLED', false),
        'base_url' => rtrim(env('AUTHENTIK_BASE_URL', 'https://auth.kinoah2k.com:4443'), '/'),
        'issuer_url' => rtrim(env('AUTHENTIK_ISSUER_URL', env('AUTHENTIK_BASE_URL', 'https://auth.kinoah2k.com:4443')), '/'),
        'client_id' => env('AUTHENTIK_CLIENT_ID'),
        'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),
        'token_auth_method' => env('AUTHENTIK_TOKEN_AUTH_METHOD', 'client_secret_post'),
        'scopes' => env('AUTHENTIK_SCOPES', 'openid email profile'),
        'default_role' => env('AUTHENTIK_DEFAULT_ROLE', 'viewer'),
    ],
];
