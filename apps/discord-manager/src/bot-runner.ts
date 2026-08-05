import { Client, Events, GatewayIntentBits } from 'discord.js';
import type { Redis } from 'ioredis';
import { AudioBufferRecorder } from './audio-buffer-recorder.js';
import type { ApiClient, BotSummary } from './api-client.js';
import { CommandHandler } from './command-handler.js';
import { DiscordChannelSyncRunner } from './discord-channel-sync-runner.js';
import type { LiveAudioServer } from './live-audio-server.js';
import { ModerationActionRunner } from './moderation-action-runner.js';
import { RedisBotLock } from './redis-lock.js';
import { TranscriptionJobRunner } from './transcription-job-runner.js';
import { VoiceAssignmentManager } from './voice-assignment-manager.js';
import { VoiceBroadcastRunner } from './voice-broadcast-runner.js';

export class BotRunner {
  private client: Client | null = null;
  private timer: NodeJS.Timeout | null = null;
  private voiceManager: VoiceAssignmentManager | null = null;
  private commandHandler: CommandHandler | null = null;
  private moderationRunner: ModerationActionRunner | null = null;
  private broadcastRunner: VoiceBroadcastRunner | null = null;
  private transcriptionRunner: TranscriptionJobRunner | null = null;
  private channelSyncRunner: DiscordChannelSyncRunner | null = null;
  private lastRestartRequestedAt: string | null;
  private readonly audioRecorder: AudioBufferRecorder;
  private readonly lock: RedisBotLock;

  constructor(
    private readonly bot: BotSummary,
    private readonly api: ApiClient,
    redis: Redis,
    private readonly workerId: string,
    ttlMs: number,
    liveAudio?: LiveAudioServer,
  ) {
    this.lock = new RedisBotLock(redis, bot.id, ttlMs);
    this.audioRecorder = new AudioBufferRecorder(undefined, liveAudio);
    this.lastRestartRequestedAt = bot.restart_requested_at ?? null;
  }

  async start(): Promise<boolean> {
    if (!await this.lock.acquire()) return false;

    try {
      const credentials = await this.api.credentials(this.bot.id);

      this.client = new Client({
        intents: [GatewayIntentBits.Guilds, GatewayIntentBits.GuildVoiceStates],
      });

      this.voiceManager = new VoiceAssignmentManager(this.client, this.api, this.bot.id, this.lock.redis, this.audioRecorder);
      this.commandHandler = new CommandHandler(this.client, this.api, this.bot.id, credentials.client_id, credentials.token, this.audioRecorder);
      this.moderationRunner = new ModerationActionRunner(this.client, this.api, this.bot.id);
      this.broadcastRunner = new VoiceBroadcastRunner(this.api, this.bot.id);
      this.transcriptionRunner = new TranscriptionJobRunner(this.api);
      this.channelSyncRunner = new DiscordChannelSyncRunner(this.client, this.api, this.bot.id);

      this.client.once(Events.ClientReady, () => void this.onReady().catch((error) => this.logError('ready_failed', error)));
      this.client.on(Events.InteractionCreate, (interaction) => void this.commandHandler?.handle(interaction));
      this.client.on(Events.VoiceStateUpdate, (oldState, newState) => this.voiceManager?.handleVoiceState(oldState, newState));
      this.client.on(Events.ChannelCreate, () => void this.channelSyncRunner?.runDue(true));
      this.client.on(Events.Error, (error) => void this.safeHeartbeat('error', error.message));

      await this.client.login(credentials.token);
      this.timer = setInterval(() => void this.tick().catch((error) => this.logError('tick_failed', error)), 20_000);

      return true;
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erreur inconnue';
      await this.safeHeartbeat('error', message);
      await this.lock.release();
      throw error;
    }
  }

  needsRestart(bot: BotSummary): boolean {
    const requestedAt = bot.restart_requested_at ?? null;

    if (requestedAt === null || requestedAt === this.lastRestartRequestedAt) {
      return false;
    }

    this.lastRestartRequestedAt = requestedAt;

    return true;
  }

  private async onReady(): Promise<void> {
    await this.channelSyncRunner?.runDue(true);
    await this.refreshAssignments();
    void this.commandHandler?.registerSafely();
    await this.voiceManager?.reconcile();
    await this.moderationRunner?.runPending();
    await this.broadcastRunner?.runPending();
    await this.transcriptionRunner?.runPending();
    await this.safeHeartbeat('online');
  }

  private async refreshAssignments(): Promise<void> {
    const assignments = await this.api.assignments(this.bot.id);
    this.voiceManager?.setAssignments(assignments.channels);
    this.commandHandler?.setAssignments(assignments.channels);
  }

  private async tick(): Promise<void> {
    if (!await this.lock.renew()) {
      await this.stop();
      return;
    }

    await this.channelSyncRunner?.runDue();
    await this.refreshAssignments();
    await this.voiceManager?.reconcile();
    await this.moderationRunner?.runPending();
    await this.broadcastRunner?.runPending();
    await this.transcriptionRunner?.runPending();
    await this.safeHeartbeat(this.client?.isReady() ? 'online' : 'connecting');
  }

  private heartbeat(status: string, error?: string): Promise<{ accepted: boolean }> {
    return this.api.heartbeat(this.bot.id, {
      worker_id: this.workerId,
      hostname: process.env.HOSTNAME ?? 'unknown',
      status,
      version: '0.1.0',
      ...(error === undefined ? {} : { error }),
    });
  }

  private async safeHeartbeat(status: string, error?: string): Promise<void> {
    try {
      await this.heartbeat(status, error);
    } catch (heartbeatError) {
      this.logError('heartbeat_failed', heartbeatError);
    }
  }

  private logError(event: string, error: unknown): void {
    console.error(JSON.stringify({
      level: 'error',
      event,
      bot_id: this.bot.id,
      message: error instanceof Error ? error.message : 'Erreur inconnue',
    }));
  }

  async stop(): Promise<void> {
    if (this.timer) clearInterval(this.timer);
    this.timer = null;
    await this.voiceManager?.stop();
    this.client?.destroy();
    this.client = null;
    await this.safeHeartbeat('offline');
    await this.lock.release();
  }
}
