import { ActionRowBuilder, ButtonBuilder, ButtonStyle, EmbedBuilder } from 'discord.js';
import type { Client, MessageCreateOptions } from 'discord.js';
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

    const roleIds = [
      ...(notification.assignment.report_mention_role_discord_ids ?? []),
      notification.assignment.moderator_role_discord_id,
    ].filter((roleId): roleId is string => typeof roleId === 'string' && /^\d{16,25}$/.test(roleId));
    const uniqueRoleIds = [...new Set(roleIds)];
    const mentionLine = uniqueRoleIds.map((roleId) => `<@&${roleId}>`).join(' ');
    const title = notification.source === 'blocked_word'
      ? 'Signalement automatique'
      : 'Nouveau signalement vocal';
    const reporter = notification.reporterUserId && /^\d{16,25}$/.test(notification.reporterUserId)
      ? `<@${notification.reporterUserId}>`
      : 'Détection automatique';
    const confidence = typeof notification.confidence === 'number'
      ? `${Math.round(notification.confidence * 100)}%`
      : null;

    const reportUrl = this.reportUrl(notification.reportId);
    const embed = new EmbedBuilder()
      .setTitle(title)
      .setDescription(this.clip(notification.reason, 700))
      .setColor(notification.source === 'blocked_word' ? 0xdc2626 : 0xf59e0b)
      .addFields(
        { name: 'Salon vocal', value: `<#${notification.assignment.channel_discord_id}>`, inline: true },
        { name: 'Utilisateur signalé', value: `<@${notification.targetUserId}>`, inline: true },
        { name: 'Auteur', value: reporter, inline: true },
        { name: 'Extraits audio', value: String(notification.clipCount), inline: true },
        { name: 'Référence', value: `\`${notification.reportId}\``, inline: false },
      )
      .setTimestamp(new Date());

    if (notification.matchedWord) {
      embed.addFields({ name: 'Mot détecté', value: this.clip(notification.matchedWord, 256), inline: true });
    }

    if (confidence) {
      embed.addFields({ name: 'Confiance', value: confidence, inline: true });
    }

    if (reportUrl) {
      embed.setURL(reportUrl);
    }

    const channel = await guild.channels.fetch(notificationChannelId).catch(() => null);
    if (!channel?.isTextBased() || !('send' in channel)) return;

    const message: MessageCreateOptions = {
      embeds: [embed],
      components: reportUrl ? [
        new ActionRowBuilder<ButtonBuilder>().addComponents(
          new ButtonBuilder()
            .setLabel('Ouvrir le signalement')
            .setStyle(ButtonStyle.Link)
            .setURL(reportUrl),
        ),
      ] : [],
      allowedMentions: {
        roles: uniqueRoleIds,
        users: notification.reporterUserId && /^\d{16,25}$/.test(notification.reporterUserId)
          ? [notification.targetUserId, notification.reporterUserId]
          : [notification.targetUserId],
      },
    };

    if (mentionLine) {
      message.content = mentionLine;
    }

    await channel.send(message).catch((error: unknown) => {
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

  private reportUrl(reportId: string): string | null {
    const baseUrl = (process.env.APP_URL ?? process.env.VOICE_GUARDIAN_URL ?? '').replace(/\/+$/, '');
    if (!baseUrl || !/^https?:\/\//.test(baseUrl)) return null;

    return `${baseUrl}/admin/voice-reports/${encodeURIComponent(reportId)}/edit`;
  }
}
