import { entersState, getVoiceConnection, joinVoiceChannel, VoiceConnectionStatus } from '@discordjs/voice';
import type { Client, GuildMember, VoiceBasedChannel, VoiceState } from 'discord.js';
import type { AudioBufferRecorder } from './audio-buffer-recorder.js';
import type { ApiClient, ChannelAssignment } from './api-client.js';

const EMPTY_CHANNEL_GRACE_MS = 30_000;

export class VoiceAssignmentManager {
  private assignments = new Map<string, ChannelAssignment>();
  private leaveTimers = new Map<string, NodeJS.Timeout>();
  private sessions = new Map<string, string>();
  private activeChannels = new Map<string, string>();
  private guildQueues = new Map<string, Promise<void>>();

  constructor(
    private readonly client: Client,
    private readonly api: ApiClient,
    private readonly botId: string,
    private readonly audioRecorder: AudioBufferRecorder,
  ) {}

  setAssignments(assignments: ChannelAssignment[]): void {
    this.assignments = new Map(assignments.map((assignment) => [assignment.channel_discord_id, assignment]));
    this.logInfo('voice_assignments_refreshed', {
      assignment_count: assignments.length,
      guild_count: new Set(assignments.map((assignment) => assignment.guild_discord_id)).size,
    });

    for (const [channelId, timer] of this.leaveTimers) {
      if (!this.assignments.has(channelId)) {
        clearTimeout(timer);
        this.leaveTimers.delete(channelId);
      }
    }
  }

  handleVoiceState(oldState: VoiceState, newState: VoiceState): void {
    void this.recordVoiceEvent(oldState, newState).catch((error) => this.logError('voice_event_failed', error));
    this.enqueueGuild(newState.guild.id, async () => {
      await this.maybeJoin(newState);
      await this.maybeScheduleLeave(oldState);
    }, 'voice_state_reconcile_failed');
  }

  async reconcile(): Promise<void> {
    const assignmentsByGuild = new Map<string, ChannelAssignment[]>();

    for (const assignment of this.assignments.values()) {
      const assignments = assignmentsByGuild.get(assignment.guild_discord_id) ?? [];
      assignments.push(assignment);
      assignmentsByGuild.set(assignment.guild_discord_id, assignments);
    }

    for (const [guildId, assignments] of assignmentsByGuild) {
      await this.enqueueGuild(guildId, async () => {
        const current = await this.currentChannel(guildId);
        this.logInfo('voice_guild_reconcile', {
          guild_id: guildId,
          assignment_count: assignments.length,
          current_channel_id: current?.id ?? null,
          current_human_member_count: current ? this.humanMembers(current).length : 0,
        });

        if (current && this.hasHumanMembers(current)) {
          await this.heartbeatCurrent(guildId, current).catch((error) => this.logError('voice_session_heartbeat_failed', error));
          return;
        }

        const next = await this.firstActiveAssignment(assignments);

        if (next) {
          await this.switchTo(next);
          return;
        }

        this.logInfo('voice_no_active_channel_found', {
          guild_id: guildId,
          assignment_count: assignments.length,
        });

        if (current) await this.scheduleLeave(current);
      }, 'voice_guild_reconcile_failed');
    }
  }

  private async maybeJoin(state: VoiceState): Promise<void> {
    const channel = state.channel;
    if (!channel || state.member?.user.bot) return;

    const assignment = this.assignments.get(channel.id);
    if (!assignment) return;

    const current = await this.currentChannel(channel.guild.id);

    if (current?.id === channel.id) {
      await this.heartbeatCurrent(channel.guild.id, channel).catch((error) => this.logError('voice_session_heartbeat_failed', error));
      return;
    }

    if (current && this.hasHumanMembers(current)) {
      return;
    }

    await this.switchTo(assignment);
  }

  private async maybeScheduleLeave(state: VoiceState): Promise<void> {
    const channel = state.channel;
    if (!channel || !this.assignments.has(channel.id) || this.hasHumanMembers(channel)) return;

    const currentChannelId = this.activeChannels.get(channel.guild.id);
    if (currentChannelId !== channel.id || this.leaveTimers.has(channel.id)) return;

    const alternatives = [...this.assignments.values()].filter(
      (assignment) => assignment.guild_discord_id === channel.guild.id && assignment.channel_discord_id !== channel.id,
    );
    const next = await this.firstActiveAssignment(alternatives);

    if (next) {
      await this.switchTo(next);
      return;
    }

    await this.scheduleLeave(channel);
  }

  private async switchTo(assignment: ChannelAssignment): Promise<void> {
    const guild = this.client.guilds.cache.get(assignment.guild_discord_id) ?? (await this.client.guilds.fetch(assignment.guild_discord_id));
    const channel = (guild.channels.cache.get(assignment.channel_discord_id) ?? (await guild.channels.fetch(assignment.channel_discord_id))) as VoiceBasedChannel | null;
    if (!channel?.isVoiceBased()) return;

    const connection = getVoiceConnection(guild.id);
    const previousSessionId = this.sessions.get(guild.id);
    let nextConnection = connection;

    if (connection) {
      if (connection.joinConfig.channelId === channel.id) {
        this.activeChannels.set(guild.id, channel.id);

        if (previousSessionId) {
          await this.heartbeatCurrent(guild.id, channel).catch((error) => this.logError('voice_session_heartbeat_failed', error));
          return;
        }
      } else {
        this.audioRecorder.detach(guild.id);
        connection.destroy();
        nextConnection = undefined;
      }
    }

    if (previousSessionId) {
      await this.api.endVoiceSession(previousSessionId).catch(() => undefined);
      this.sessions.delete(guild.id);
    }

    const members = this.humanMembers(channel);

    if (!nextConnection) {
      nextConnection = joinVoiceChannel({
        channelId: channel.id,
        guildId: guild.id,
        adapterCreator: guild.voiceAdapterCreator,
        selfDeaf: false,
        selfMute: false,
      });
    }

    await entersState(nextConnection, VoiceConnectionStatus.Ready, 25_000);
    this.logInfo('voice_joined', { guild_id: guild.id, channel_id: channel.id, channel_name: channel.name });

    const session = await this.api.createVoiceSession({
      discord_bot_id: this.botId,
      discord_guild_id: assignment.guild_id,
      discord_channel_id: assignment.channel_id,
      member_count: members.length,
      members,
    });

    this.sessions.set(guild.id, session.id);
    this.activeChannels.set(guild.id, channel.id);
    this.clearLeaveTimer(channel.id);
    this.audioRecorder.attach(nextConnection, channel.id, session.id, assignment.buffer_seconds);
  }

  private async scheduleLeave(channel: VoiceBasedChannel): Promise<void> {
    if (this.leaveTimers.has(channel.id)) return;

    const sessionId = this.sessions.get(channel.guild.id);
    if (sessionId) await this.api.voiceSessionHeartbeat(sessionId, { member_count: 0, status: 'empty', members: [] }).catch((error) => this.logError('voice_session_empty_heartbeat_failed', error));

    this.leaveTimers.set(
      channel.id,
      setTimeout(() => {
        void this.leave(channel.guild.id, channel.id);
      }, EMPTY_CHANNEL_GRACE_MS),
    );
  }

  private async leave(guildId: string, channelId: string): Promise<void> {
    const current = await this.currentChannel(guildId);
    if (current?.id !== channelId || this.hasHumanMembers(current)) return;

    getVoiceConnection(guildId)?.destroy();
    this.audioRecorder.detach(guildId);
    this.activeChannels.delete(guildId);
    this.leaveTimers.delete(channelId);

    const sessionId = this.sessions.get(guildId);
    if (sessionId) {
      await this.api.endVoiceSession(sessionId).catch(() => undefined);
      this.sessions.delete(guildId);
    }
  }

  private async currentChannel(guildId: string): Promise<VoiceBasedChannel | null> {
    const channelId = this.activeChannels.get(guildId) ?? getVoiceConnection(guildId)?.joinConfig.channelId;
    if (!channelId) return null;

    const guild = this.client.guilds.cache.get(guildId) ?? (await this.client.guilds.fetch(guildId).catch(() => null));
    const channel = guild?.channels.cache.get(channelId) ?? (await guild?.channels.fetch(channelId).catch(() => null));

    return channel?.isVoiceBased() ? channel : null;
  }

  private async heartbeatCurrent(guildId: string, channel: VoiceBasedChannel): Promise<void> {
    const sessionId = this.sessions.get(guildId);
    if (!sessionId) return;

    const members = this.humanMembers(channel);
    await this.api.voiceSessionHeartbeat(sessionId, { member_count: members.length, status: 'active', members });
  }

  private logError(event: string, error: unknown): void {
    console.error(JSON.stringify({ level: 'error', event, message: error instanceof Error ? error.message : 'unknown' }));
  }

  private async firstActiveAssignment(assignments: ChannelAssignment[]): Promise<ChannelAssignment | null> {
    for (const assignment of assignments) {
      const guild = this.client.guilds.cache.get(assignment.guild_discord_id) ?? (await this.client.guilds.fetch(assignment.guild_discord_id).catch(() => null));
      const channel = guild?.channels.cache.get(assignment.channel_discord_id) ?? (await guild?.channels.fetch(assignment.channel_discord_id).catch(() => null));

      if (!channel?.isVoiceBased()) {
        this.logInfo('voice_assignment_unavailable', {
          guild_id: assignment.guild_discord_id,
          channel_id: assignment.channel_discord_id,
        });
        continue;
      }

      const members = this.humanMembers(channel);
      this.logInfo('voice_assignment_checked', {
        guild_id: assignment.guild_discord_id,
        channel_id: assignment.channel_discord_id,
        channel_name: channel.name,
        human_member_count: members.length,
      });

      if (members.length > 0) return assignment;
    }

    return null;
  }

  private async recordVoiceEvent(oldState: VoiceState, newState: VoiceState): Promise<void> {
    const member = newState.member ?? oldState.member;
    if (!member || member.user.bot) return;

    const oldChannel = oldState.channel;
    const newChannel = newState.channel;
    const watchedOld = oldChannel ? this.assignments.has(oldChannel.id) : false;
    const watchedNew = newChannel ? this.assignments.has(newChannel.id) : false;
    if (!watchedOld && !watchedNew) return;

    const channelId = (newChannel ?? oldChannel)?.id;
    const guildId = (newState.guild ?? oldState.guild).id;
    const basePayload = {
      display_name: member.displayName,
      old_channel_id: oldChannel?.id,
      new_channel_id: newChannel?.id,
      self_mute: newState.selfMute,
      server_mute: newState.serverMute,
      self_deaf: newState.selfDeaf,
      server_deaf: newState.serverDeaf,
      streaming: newState.streaming,
    };
    const emit = (
      type:
        | 'voice_join'
        | 'voice_leave'
        | 'voice_move'
        | 'voice_mute'
        | 'voice_unmute'
        | 'voice_deafen'
        | 'voice_undeafen'
        | 'screen_share_start'
        | 'screen_share_stop',
    ) =>
      this.api
        .createVoiceEvent({
          guild_discord_id: guildId,
          discord_user_id: member.id,
          type,
          payload: basePayload,
          occurred_at: new Date().toISOString(),
          ...(channelId === undefined ? {} : { channel_discord_id: channelId }),
        })
        .catch(() => undefined);

    if (oldChannel?.id !== newChannel?.id) {
      await emit(!oldChannel && newChannel ? 'voice_join' : oldChannel && !newChannel ? 'voice_leave' : 'voice_move');
      return;
    }

    const wasMuted = oldState.selfMute || oldState.serverMute;
    const isMuted = newState.selfMute || newState.serverMute;
    if (wasMuted !== isMuted) await emit(isMuted ? 'voice_mute' : 'voice_unmute');

    const wasDeafened = oldState.selfDeaf || oldState.serverDeaf;
    const isDeafened = newState.selfDeaf || newState.serverDeaf;
    if (wasDeafened !== isDeafened) await emit(isDeafened ? 'voice_deafen' : 'voice_undeafen');

    if (oldState.streaming !== newState.streaming) await emit(newState.streaming ? 'screen_share_start' : 'screen_share_stop');
  }

  private enqueueGuild(guildId: string, task: () => Promise<void>, event: string): Promise<void> {
    const previous = this.guildQueues.get(guildId) ?? Promise.resolve();
    const next = previous
      .catch(() => undefined)
      .then(task)
      .catch((error) => this.logError(event, error))
      .finally(() => {
        if (this.guildQueues.get(guildId) === next) this.guildQueues.delete(guildId);
      });

    this.guildQueues.set(guildId, next);
    return next;
  }

  private clearLeaveTimer(channelId: string): void {
    const timer = this.leaveTimers.get(channelId);
    if (!timer) return;

    clearTimeout(timer);
    this.leaveTimers.delete(channelId);
  }

  private hasHumanMembers(channel: VoiceBasedChannel): boolean {
    return this.humanMembers(channel).length > 0;
  }

  private humanMembers(channel: VoiceBasedChannel): { discord_user_id: string; display_name: string }[] {
    const voiceStateMembers = channel.guild.voiceStates.cache
      .filter((state) => state.channelId === channel.id)
      .map((state) => state.member)
      .filter((member): member is GuildMember => member !== null && !member.user.bot);

    const members = voiceStateMembers.length > 0
      ? voiceStateMembers
      : channel.members.filter((member) => !member.user.bot).map((member) => member);

    return members.map((member) => ({ discord_user_id: member.id, display_name: member.displayName }));
  }

  private logInfo(event: string, context: Record<string, unknown> = {}): void {
    console.log(JSON.stringify({ level: 'info', event, ...context }));
  }
}
