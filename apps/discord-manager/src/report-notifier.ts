import type { Client } from 'discord.js';
import type { ChannelAssignment } from './api-client.js';

export interface ReportNotification {
  assignment: ChannelAssignment;
  reportId: string;
  targetUserId: string;
  reporterUserId?: string | null;
  reason: string;
  clipCount: number;
  source: 'manual' | 'blocked_word';
  matchedWord?: string | null;
  confidence?: number | null;
}

export class ReportNotifier {
  constructor(private readonly client: Client) {}

  async notify(notification: ReportNotification): Promise<void> {
    const notificationChannelId = notification.assignment.report_notification_channel_discord_id;
    if (!notificationChannelId) return;

    const guild = this.client.guilds.cache.get(notification.assignment.guild_discord_id)
      ?? await this.client.guilds.fetch(notification.assignment.guild_discord_id).catch(() => null);
    if (!guild) return;

    const roleIds = (notification.assignment.report_mention_role_discord_ids ?? [])
      .filter((roleId) => /^\d{16,25}$/.test(roleId));
    const mentionLine = roleIds.map((roleId) => `<@&${roleId}>`).join(' ');
    const title = notification.source === 'blocked_word'
      ? '**Signalement automatique - mot bloqué détecté**'
      : '**Nouveau signalement vocal**';
    const reporter = notification.reporterUserId && /^\d{16,25}$/.test(notification.reporterUserId)
      ? `<@${notification.reporterUserId}>`
      : 'Détection automatique';
    const confidence = typeof notification.confidence === 'number'
      ? `${Math.round(notification.confidence * 100)}%`
      : null;

    const content = [
      mentionLine,
      title,
      `Salon vocal : <#${notification.assignment.channel_discord_id}>`,
      `Utilisateur signalé : <@${notification.targetUserId}>`,
      `Auteur : ${reporter}`,
      notification.matchedWord ? `Mot détecté : ${notification.matchedWord}` : null,
      confidence ? `Confiance : ${confidence}` : null,
      `Extraits audio : ${notification.clipCount}`,
      `Référence : \`${notification.reportId}\``,
      `Motif : ${this.clip(notification.reason, 700)}`,
    ].filter(Boolean).join('\n');

    const channel = await guild.channels.fetch(notificationChannelId).catch(() => null);
    if (!channel?.isTextBased() || !('send' in channel)) return;

    await channel.send({
      content,
      allowedMentions: {
        roles: roleIds,
        users: notification.reporterUserId && /^\d{16,25}$/.test(notification.reporterUserId)
          ? [notification.targetUserId, notification.reporterUserId]
          : [notification.targetUserId],
      },
    }).catch((error: unknown) => {
      console.error(JSON.stringify({
        level: 'error',
        event: 'report_notification_failed',
        message: error instanceof Error ? error.message : 'unknown',
        report_id: notification.reportId,
      }));
    });
  }

  private clip(value: string, maxLength: number): string {
    return value.length > maxLength ? `${value.slice(0, maxLength - 3)}...` : value;
  }
}
