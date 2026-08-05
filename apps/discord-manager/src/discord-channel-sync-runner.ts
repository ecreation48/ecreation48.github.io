import { ChannelType, type Client, type GuildBasedChannel } from 'discord.js';
import type { ApiClient } from './api-client.js';

const SYNCABLE_CHANNEL_TYPES = new Set<number>([
  ChannelType.GuildText,
  ChannelType.GuildAnnouncement,
  ChannelType.GuildVoice,
  ChannelType.GuildStageVoice,
]);

export class DiscordChannelSyncRunner {
  private lastSyncAt = 0;
  private running = false;
  private readonly intervalMs = Number(process.env.DISCORD_CHANNEL_SYNC_INTERVAL_MS ?? 120_000);

  constructor(
    private readonly client: Client,
    private readonly api: ApiClient,
    private readonly botId: string,
  ) {}

  async runDue(force = false): Promise<void> {
    if (this.running) return;
    if (!force && Date.now() - this.lastSyncAt < this.intervalMs) return;

    this.running = true;

    try {
      for (const [, guild] of this.client.guilds.cache) {
        await guild.channels.fetch().catch(() => null);
        await guild.roles.fetch().catch(() => null);

        const channels = guild.channels.cache
          .filter((channel): channel is GuildBasedChannel => SYNCABLE_CHANNEL_TYPES.has(channel.type))
          .map((channel) => ({
            id: channel.id,
            name: channel.name,
            type: channel.type,
            parent_id: 'parentId' in channel ? channel.parentId : null,
            user_limit: 'userLimit' in channel ? channel.userLimit ?? 0 : 0,
          }));

        const roles = guild.roles.cache
          .filter((role) => !role.managed)
          .map((role) => ({
            id: role.id,
            name: typeof role.name === 'string' && role.name.trim() !== '' ? role.name : role.id,
            position: role.position,
          }));

        await this.api.syncGuildChannels(this.botId, guild.id, {
          guild: { name: guild.name, owner_id: guild.ownerId },
          channels,
          roles,
        }).catch((error: unknown) => {
          console.error(JSON.stringify({
            level: 'error',
            event: 'guild_channels_sync_failed',
            guild_id: guild.id,
            message: error instanceof Error ? error.message : 'unknown',
          }));
        });
      }

      this.lastSyncAt = Date.now();
    } finally {
      this.running = false;
    }
  }
}
