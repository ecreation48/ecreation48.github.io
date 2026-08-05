<?php

namespace App\Http\Controllers;

use App\Models\DiscordChannel;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LiveVoiceChannelStreamController
{
    public function __invoke(DiscordChannel $discordChannel): StreamedResponse|Response
    {
        abort_unless(auth()->user()?->canListenLiveAudio() ?? false, 403);
        abort_unless($discordChannel->isVoiceBased(), 404);

        @set_time_limit(0);
        @ini_set('default_socket_timeout', '86400');
        ignore_user_abort(false);

        $url = sprintf(
            'http://%s:%d/live/%s/%s',
            config('services.worker.live_audio_host', '127.0.0.1'),
            (int) config('services.worker.live_audio_port', 8787),
            rawurlencode($discordChannel->guild->discord_id),
            rawurlencode($discordChannel->discord_id),
        );

        $stream = $this->openStream($url);

        if ($stream === false) {
            $host = config('services.worker.live_audio_host', '127.0.0.1');
            $port = (int) config('services.worker.live_audio_port', 8787);

            return response(
                "Flux audio live indisponible sur {$host}:{$port}. Relance le worker puis vérifie la Console worker : tu dois voir live_audio_server_started.",
                503,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        stream_set_timeout($stream, 86400);

        return response()->stream(function () use ($stream): void {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            while (! feof($stream)) {
                $chunk = fread($stream, 8192);

                if ($chunk === false) {
                    break;
                }

                echo $chunk;
                flush();
            }

            fclose($stream);
        }, 200, [
            'Content-Type' => 'audio/wav',
            'Cache-Control' => 'no-store',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function openStream(string $url)
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 86400,
                'ignore_errors' => true,
            ],
        ]);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $stream = @fopen($url, 'rb', false, $context);

            if ($stream !== false) {
                return $stream;
            }

            usleep(250_000);
        }

        return false;
    }
}
