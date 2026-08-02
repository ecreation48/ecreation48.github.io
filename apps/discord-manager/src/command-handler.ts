import {
  ActionRowBuilder,
  ApplicationCommandOptionType,
  ButtonBuilder,
  ButtonStyle,
  MessageFlags,
  ModalBuilder,
  REST,
  Routes,
  TextInputBuilder,
  TextInputStyle,
} from 'discord.js';
import type {
  ButtonInteraction,
  ChatInputCommandInteraction,
  Client,
  GuildMember,
  Interaction,
  ModalSubmitInteraction,
} from 'discord.js';
import type { AudioBufferRecorder } from './audio-buffer-recorder.js';
import type { ApiClient, ChannelAssignment } from './api-client.js';

const REPORT_BUTTON_ID = 'voice-guardian:report';
const REPORT_MODAL_ID = 'voice-guardian:report-modal';
const REPORT_REASON_ID = 'voice-guardian:report-reason';
const REPORT_TARGET_ID = 'voice-guardian:report-target';

const COMMANDS = [
  {
    name: 'report-panel',
    description: 'Publier le bouton de signalement vocal dans ce salon',
  },
  {
    name: 'report-vc',
    description: 'Signaler un comportement dans un salon vocal',
    options: [
      {
        name: 'utilisateur',
        description: 'Utilisateur à signaler',
        type: ApplicationCommandOptionType.User,
        required: true,
      },
      {
        name: 'motif',
        description: 'Motif du signalement',
        type: ApplicationCommandOptionType.String,
        required: true,
      },
      {
        name: 'commentaire',
        description: 'Commentaire facultatif',
        type: ApplicationCommandOptionType.String,
        required: false,
      },
    ],
  },
  { name: 'voice-status', description: 'Afficher les salons vocaux surveillés' },
  { name: 'privacy', description: 'Afficher la politique de confidentialité vocale' },
];

export class CommandHandler {
  private assignments: ChannelAssignment[] = [];

  constructor(
    private readonly client: Client,
    private readonly api: ApiClient,
    private readonly botId: string,
    private readonly clientId: string,
    private readonly token: string,
    private readonly audioRecorder: AudioBufferRecorder,
  ) {}

  setAssignments(assignments: ChannelAssignment[]): void {
    this.assignments = assignments;
  }

  async register(): Promise<void> {
    const rest = new REST({ version: '10' }).setToken(this.token);
    const guildIds = [...new Set(this.assignments.map((assignment) => assignment.guild_discord_id))];

    if (guildIds.length === 0) {
      await rest.put(Routes.applicationCommands(this.clientId), { body: COMMANDS });
      return;
    }

    await Promise.all(
      guildIds.map((guildId) => rest.put(Routes.applicationGuildCommands(this.clientId, guildId), { body: COMMANDS })),
    );
  }

  async registerSafely(): Promise<void> {
    try {
      await this.register();
      console.log(JSON.stringify({ level: 'info', event: 'commands_registered', guild_count: new Set(this.assignments.map((assignment) => assignment.guild_discord_id)).size }));
    } catch (error) {
      console.error(JSON.stringify({ level: 'error', event: 'commands_register_failed', message: error instanceof Error ? error.message : 'unknown' }));
    }
  }

  async handle(interaction: Interaction): Promise<void> {
    if (interaction.isChatInputCommand()) {
      await this.handleCommand(interaction);
      return;
    }

    if (interaction.isButton() && interaction.customId === REPORT_BUTTON_ID) {
      await this.openReportModal(interaction);
      return;
    }

    if (interaction.isModalSubmit() && interaction.customId === REPORT_MODAL_ID) {
      await this.reportFromModal(interaction);
    }
  }

  private async handleCommand(interaction: ChatInputCommandInteraction): Promise<void> {
    if (interaction.commandName === 'privacy') {
      await interaction.reply({
        flags: MessageFlags.Ephemeral,
        content:
          'La plateforme conserve uniquement un tampon temporaire pour les salons surveillés. Un fichier audio ne sera créé que lors d’un signalement, avec accès limité aux modérateurs et suppression selon la durée de conservation configurée.',
      });
      return;
    }

    if (interaction.commandName === 'voice-status') {
      const lines = this.assignments.map(
        (assignment) =>
          `• salon ${assignment.channel_discord_id}, buffer ${assignment.buffer_seconds}s${assignment.transcription_enabled ? ' avec transcription' : ''}`,
      );

      await interaction.reply({
        flags: MessageFlags.Ephemeral,
        content: lines.length === 0 ? 'Aucun salon vocal surveillé pour ce bot.' : `Salons vocaux surveillés :\n${lines.join('\n')}`,
      });
      return;
    }

    if (interaction.commandName === 'report-panel') {
      await this.publishReportButton(interaction);
      return;
    }

    if (interaction.commandName === 'report-vc') {
      await this.reportFromSlashCommand(interaction);
    }
  }

  private async publishReportButton(interaction: ChatInputCommandInteraction): Promise<void> {
    const button = new ButtonBuilder()
      .setCustomId(REPORT_BUTTON_ID)
      .setLabel('Signaler un comportement vocal')
      .setStyle(ButtonStyle.Danger);

    const row = new ActionRowBuilder<ButtonBuilder>().addComponents(button);

    await interaction.reply({
      content:
        'Utilise ce bouton pour signaler rapidement un problème vocal. Indique le moment précis, ce qui s’est passé, et la personne concernée si tu la connais.',
      components: [row],
    });
  }

  private async openReportModal(interaction: ButtonInteraction): Promise<void> {
    const modal = new ModalBuilder().setCustomId(REPORT_MODAL_ID).setTitle('Signaler un comportement vocal');

    const reason = new TextInputBuilder()
      .setCustomId(REPORT_REASON_ID)
      .setLabel('Raison du report')
      .setPlaceholder('Sois précis : moment, contexte, propos, action...')
      .setStyle(TextInputStyle.Paragraph)
      .setRequired(true)
      .setMinLength(10)
      .setMaxLength(1000);

    const target = new TextInputBuilder()
      .setCustomId(REPORT_TARGET_ID)
      .setLabel('Personne à report')
      .setPlaceholder('Facultatif : mention, ID, pseudo ou nom affiché')
      .setStyle(TextInputStyle.Short)
      .setRequired(false)
      .setMaxLength(120);

    modal.addComponents(
      new ActionRowBuilder<TextInputBuilder>().addComponents(reason),
      new ActionRowBuilder<TextInputBuilder>().addComponents(target),
    );

    await interaction.showModal(modal);
  }

  private async reportFromSlashCommand(interaction: ChatInputCommandInteraction): Promise<void> {
    const target = interaction.options.getUser('utilisateur', true);
    const reason = interaction.options.getString('motif', true);
    const comment = interaction.options.getString('commentaire') ?? undefined;
    const member = interaction.guild?.members.cache.get(target.id);
    const channelId = member?.voice.channelId;
    const assignment = channelId ? this.assignments.find((candidate) => candidate.channel_discord_id === channelId) : undefined;

    if (!interaction.guildId || !assignment) {
      await interaction.reply({
        flags: MessageFlags.Ephemeral,
        content: 'Impossible de créer le signalement : l’utilisateur doit être dans un salon vocal surveillé par ce bot.',
      });
      return;
    }

    await this.createReport(interaction, assignment, target.id, reason, comment);
  }

  private async reportFromModal(interaction: ModalSubmitInteraction): Promise<void> {
    const reporter = interaction.member instanceof Object ? (interaction.member as GuildMember) : null;
    const channelId = reporter?.voice.channelId;
    const assignment = channelId ? this.assignments.find((candidate) => candidate.channel_discord_id === channelId) : undefined;

    if (!interaction.guildId || !assignment) {
      await interaction.reply({
        flags: MessageFlags.Ephemeral,
        content: 'Impossible de créer le signalement : tu dois être dans un salon vocal surveillé par ce bot.',
      });
      return;
    }

    const reason = interaction.fields.getTextInputValue(REPORT_REASON_ID).trim();
    const targetInput = interaction.fields.getTextInputValue(REPORT_TARGET_ID).trim();
    const targetId = targetInput ? this.resolveTargetUserId(interaction, targetInput) : interaction.user.id;
    const comment = targetInput
      ? `Personne indiquée dans le formulaire : ${targetInput}${targetId === interaction.user.id ? ' (non trouvée automatiquement)' : ''}`
      : 'Personne à signaler non précisée dans le formulaire.';

    await this.createReport(interaction, assignment, targetId, reason, comment);
  }

  private resolveTargetUserId(interaction: ModalSubmitInteraction, input: string): string {
    const mention = input.match(/^<@!?(\d+)>$/);
    if (mention) return mention[1] ?? interaction.user.id;

    const rawId = input.match(/^\d{16,25}$/);
    if (rawId) return input;

    const normalized = input.toLowerCase();
    const member = interaction.guild?.members.cache.find((candidate) => {
      return (
        candidate.displayName.toLowerCase() === normalized ||
        candidate.user.username.toLowerCase() === normalized ||
        candidate.user.globalName?.toLowerCase() === normalized
      );
    });

    return member?.id ?? interaction.user.id;
  }

  private async createReport(
    interaction: ChatInputCommandInteraction | ModalSubmitInteraction,
    assignment: ChannelAssignment,
    targetUserId: string,
    reason: string,
    comment?: string,
  ): Promise<void> {
    if (!interaction.guildId) return;

    const report = await this.api.createVoiceReport({
      guild_discord_id: interaction.guildId,
      channel_discord_id: assignment.channel_discord_id,
      reported_user_discord_id: targetUserId,
      reporter_user_discord_id: interaction.user.id,
      reason,
      ...(comment === undefined ? {} : { comment }),
    });

    const reporter = interaction.member instanceof Object ? (interaction.member as GuildMember) : null;
    const channel = reporter?.voice.channel ?? interaction.guild?.members.cache.get(targetUserId)?.voice.channel;
    const userIds = channel?.members.filter((candidate) => !candidate.user.bot).map((candidate) => candidate.id) ?? [targetUserId];
    let clipCount = 0;

    for (const userId of userIds) {
      const clip = await this.audioRecorder.snapshot(interaction.guildId, userId, `${report.id}-${userId}`);
      if (!clip) continue;

      await this.api
        .createAudioClip({
          voice_report_id: report.id,
          guild_discord_id: interaction.guildId,
          channel_discord_id: assignment.channel_discord_id,
          reported_user_discord_id: userId,
          storage_path: clip.path,
          mime_type: clip.mimeType,
          size_bytes: clip.bytes,
          duration_seconds: clip.durationSeconds,
          captured_from: clip.capturedFrom,
          captured_until: clip.capturedUntil,
        })
        .then(async (createdClip) => {
          clipCount++;
          if (!assignment.transcription_enabled) return;

          await this.api.createTranscript({
            voice_report_id: report.id,
            voice_audio_clip_id: createdClip.id,
            reported_user_discord_id: userId,
            status: 'pending',
          }).catch((error: unknown) => {
            console.error(JSON.stringify({ level: 'error', event: 'transcript_enqueue_failed', message: error instanceof Error ? error.message : 'unknown', report_id: report.id, clip_id: createdClip.id }));
          });
        })
        .catch(() => undefined);
    }

    await interaction.reply({
      flags: MessageFlags.Ephemeral,
      content:
        clipCount > 0
          ? `Signalement créé avec ${clipCount} extrait(s) audio. Référence : ${report.id}`
          : `Signalement créé sans extrait audio disponible. Référence : ${report.id}`,
    });

    await this.notifyReportCreated(interaction, assignment, report.id, targetUserId, reason, clipCount);
  }

  private async notifyReportCreated(
    interaction: ChatInputCommandInteraction | ModalSubmitInteraction,
    assignment: ChannelAssignment,
    reportId: string,
    targetUserId: string,
    reason: string,
    clipCount: number,
  ): Promise<void> {
    const notificationChannelId = assignment.report_notification_channel_discord_id;
    if (!notificationChannelId || !interaction.guild) return;

    const rawRoles = assignment.report_mention_role_discord_ids ?? [];
    const roleIds = rawRoles.filter((roleId) => /^\d{16,25}$/.test(roleId));
    const mentionLine = roleIds.map((roleId) => `<@&${roleId}>`).join(' ');
    const voiceChannelMention = `<#${assignment.channel_discord_id}>`;
    const targetMention = `<@${targetUserId}>`;
    const reporterMention = `<@${interaction.user.id}>`;
    const clippedReason = reason.length > 700 ? `${reason.slice(0, 697)}...` : reason;
    const content = [
      mentionLine,
      '**Nouveau signalement vocal**',
      `Salon vocal : ${voiceChannelMention}`,
      `Signalé : ${targetMention}`,
      `Auteur : ${reporterMention}`,
      `Extraits audio : ${clipCount}`,
      `Référence : \`${reportId}\``,
      `Motif : ${clippedReason}`,
    ].filter(Boolean).join('\n');

    const channel = await interaction.guild.channels.fetch(notificationChannelId).catch(() => null);
    if (!channel?.isTextBased() || !('send' in channel)) return;

    await channel.send({
      content,
      allowedMentions: { roles: roleIds, users: [targetUserId, interaction.user.id] },
    }).catch((error: unknown) => {
      console.error(JSON.stringify({ level: 'error', event: 'report_notification_failed', message: error instanceof Error ? error.message : 'unknown', report_id: reportId }));
    });
  }
}
