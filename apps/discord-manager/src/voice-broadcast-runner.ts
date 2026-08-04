import { createAudioPlayer, createAudioResource, getVoiceConnection, StreamType, AudioPlayerStatus } from '@discordjs/voice';
import { spawn } from 'node:child_process';
import { createReadStream, statSync } from 'node:fs';
import type { Readable } from 'node:stream';
import type { ApiClient, VoiceBroadcastJob } from './api-client.js';

export class VoiceBroadcastRunner {
  private running = false;
  private readonly connectionGroup: string;

  constructor(private readonly api: ApiClient, private readonly botId: string) {
    this.connectionGroup = `bot:${botId}`;
  }

  async runPending(): Promise<void> {
    if (this.running) return;

    this.running = true;

    try {
      const jobs = await this.api.voiceBroadcasts(this.botId);

      for (const job of jobs) {
        await this.run(job);
      }
    } finally {
      this.running = false;
    }
  }

  private async run(job: VoiceBroadcastJob): Promise<void> {
    try {
      if (!job.storage_path) throw new Error('Fichier audio introuvable.');

      const connection = getVoiceConnection(job.guild_discord_id, this.connectionGroup);
      if (!connection) throw new Error('Le bot n’est pas connecté à un salon vocal sur ce serveur.');
      if (connection.joinConfig.channelId !== job.channel_discord_id) throw new Error('Le bot est connecté à un autre salon vocal.');

      statSync(job.storage_path);
      await this.api.updateVoiceBroadcast(job.id, { status: 'playing' });
      await this.playFile(connection, job.storage_path, job.mime_type);
      await this.api.updateVoiceBroadcast(job.id, { status: 'success' });
    } catch (error) {
      await this.api.updateVoiceBroadcast(job.id, {
        status: 'failed',
        error_message: error instanceof Error ? error.message : 'Erreur inconnue',
      }).catch(() => undefined);
    }
  }

  private playFile(connection: NonNullable<ReturnType<typeof getVoiceConnection>>, path: string, mimeType?: string | null): Promise<void> {
    return new Promise((resolve, reject) => {
      const player = createAudioPlayer();
      const stream = this.createAudioStream(path, mimeType);
      const resource = createAudioResource(stream, { inputType: StreamType.Raw });
      const subscription = connection.subscribe(player);
      let settled = false;

      const finish = (error?: Error) => {
        if (settled) return;
        settled = true;
        subscription?.unsubscribe();
        if (error) {
          reject(error);
          return;
        }

        resolve();
      };

      player.once(AudioPlayerStatus.Idle, () => {
        finish();
      });
      player.once('error', (error) => {
        finish(error);
      });
      stream.once('error', (error) => finish(error));

      player.play(resource);
    });
  }

  private createAudioStream(path: string, mimeType?: string | null): Readable {
    if (this.isWav(path, mimeType)) {
      return createReadStream(path, { start: 44 });
    }

    return this.createFfmpegStream(path);
  }

  private isWav(path: string, mimeType?: string | null): boolean {
    return mimeType?.includes('wav') === true || path.toLowerCase().endsWith('.wav');
  }

  private createFfmpegStream(path: string): Readable {
    const ffmpegPath = process.env.FFMPEG_PATH ?? 'ffmpeg';
    const ffmpeg = spawn(ffmpegPath, [
      '-hide_banner',
      '-loglevel',
      'error',
      '-i',
      path,
      '-f',
      's16le',
      '-ar',
      '48000',
      '-ac',
      '2',
      'pipe:1',
    ], { stdio: ['ignore', 'pipe', 'pipe'] });

    let stderr = '';

    ffmpeg.stderr.on('data', (chunk) => {
      stderr += chunk.toString();
    });
    ffmpeg.once('error', (error) => {
      ffmpeg.stdout.destroy(new Error(`FFmpeg introuvable ou impossible à démarrer : ${error.message}`));
    });
    ffmpeg.once('close', (code) => {
      if (code && code !== 0) {
        ffmpeg.stdout.destroy(new Error(`Conversion audio échouée (${code}) : ${stderr.trim()}`));
      }
    });

    return ffmpeg.stdout;
  }
}
