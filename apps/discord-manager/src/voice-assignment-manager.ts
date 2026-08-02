import { entersState, getVoiceConnection, joinVoiceChannel, VoiceConnectionStatus, type VoiceConnection } from '@discordjs/voice';
import type { Client, GuildMember, VoiceBasedChannel, VoiceState } from 'discord.js';
import type { AudioBufferRecorder } from './audio-buffer-recorder.js';
import type { ApiClient, ChannelAssignment } from './api-client.js';

const EMPTY_CHANNEL_GRACE_MS = 30_000;
const DISCONNECTED_RECOVERY_GRACE_MS = Number(process.env.VOICE_DISCONNECTED_RECOVERY_GRACE_MS ?? 8_000);
const voiceDebugEnabled = process.env.VOICE_DEBUG === 'true';
const voiceDaveEncryptionEnabled = process.env.VOICE_DAVE_ENCRYPTION !== 'false';
const voiceSelfDeafEnabled = process.env.VOICE_SELF_DEAF === 'true';

export class VoiceAssignmentManager {
  private assignments = new Map<string, ChannelAssignment>();
  private leaveTimers = new Map<string, NodeJS.Timeout>();
  private sessions = new Map<string, string>();
  private activeChannels = new Map<string, string>();
  private guildQueues = new Map<string, Promise<void>>();
  private watchedConnections = new WeakSet<VoiceConnection>();
  private recoveringGuilds = new Set<string>();
  private joinAttempts = new Map<string, number>();

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
        if (this.recoveringGuilds.has(guildId)) {
          this.logInfo('voice_guild_reconcile_deferred_during_recovery', { guild_id: guildId });
          return;
        }

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

        const next = await this.preferredActiveAssignment(guildId, assignments);

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
    if (this.recoveringGuilds.has(channel.guild.id)) {
      this.logInfo('voice_join_deferred_during_recovery', {
        guild_id: channel.guild.id,
        channel_id: channel.id,
      });
      return;
    }

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

  private async switchTo(assignment: ChannelAssignment, options: { forceDisconnected?: boolean } = {}): Promise<void> {
    const guild = this.client.guilds.cache.get(assignment.guild_discord_id) ?? (await this.client.guilds.fetch(assignment.guild_discord_id));
    const channel = (guild.channels.cache.get(assignment.channel_discord_id) ?? (await guild.channels.fetch(assignment.channel_discord_id))) as VoiceBasedChannel | null;
    if (!channel?.isVoiceBased()) return;

    const connection = getVoiceConnection(guild.id);
    const previousSessionId = this.sessions.get(guild.id);
    let nextConnection = connection;

    if (connection) {
      if ([VoiceConnectionStatus.Destroyed, VoiceConnectionStatus.Disconnected].includes(connection.state.status)) {
        if (connection.state.status === VoiceConnectionStatus.Disconnected && this.recoveringGuilds.has(guild.id) && !options.forceDisconnected) {
          this.logInfo('voice_switch_deferred_during_recovery', {
            guild_id: guild.id,
            channel_id: connection.joinConfig.channelId,
            target_channel_id: channel.id,
          });
          return;
        }

        this.logInfo('voice_connection_recreate_required', {
          guild_id: guild.id,
          channel_id: connection.joinConfig.channelId,
          status: connection.state.status,
        });

        this.audioRecorder.detach(guild.id);
        this.activeChannels.delete(guild.id);
        this.recoveringGuilds.delete(guild.id);

        if (connection.state.status !== VoiceConnectionStatus.Destroyed) {
          connection.destroy();
        }

        nextConnection = undefined;
      } else if (connection.joinConfig.channelId === channel.id) {
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
    const joinAttempt = this.nextJoinAttempt(guild.id);

    if (!nextConnection) {
      nextConnection = joinVoiceChannel({
        channelId: channel.id,
        guildId: guild.id,
        adapterCreator: guild.voiceAdapterCreator,
        daveEncryption: voiceDaveEncryptionEnabled,
        debug: voiceDebugEnabled,
        selfDeaf: voiceSelfDeafEnabled,
        selfMute: false,
      });
    }

    this.watchConnection(nextConnection, guild.id, channel.id);
    try {
      await entersState(nextConnection, VoiceConnectionStatus.Ready, 25_000);
    } catch (error) {
      if (!this.isCurrentJoinAttempt(guild.id, joinAttempt)) {
        this.logInfo('voice_connection_stale_ready_timeout_ignored', {
          guild_id: guild.id,
          channel_id: channel.id,
          join_attempt: joinAttempt,
        });
        return;
      }

      this.logError('voice_connection_ready_timeout', error);
      this.audioRecorder.detach(guild.id);
      this.activeChannels.delete(guild.id);
      this.sessions.delete(guild.id);

      if (nextConnection.state.status !== VoiceConnectionStatus.Destroyed) {
        nextConnection.destroy();
      }

      throw error;
    }

    if (!this.isCurrentJoinAttempt(guild.id, joinAttempt)) {
      this.logInfo('voice_connection_stale_ready_ignored', {
        guild_id: guild.id,
        channel_id: channel.id,
        join_attempt: joinAttempt,
      });
      return;
    }

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

  private watchConnection(connection: VoiceConnection, guildId: string, channelId: string): void {
    if (this.watchedConnections.has(connection)) return;
    this.watchedConnections.add(connection);

    connection.on('stateChange', (oldState, newState) => {
      this.logInfo('voice_connection_state_changed', {
        guild_id: guildId,
        channel_id: channelId,
        old_status: oldState.status,
        new_status: newState.status,
        reason: 'reason' in newState ? newState.reason : null,
        close_code: 'closeCode' in newState ? newState.closeCode : null,
      });

      if (newState.status === VoiceConnectionStatus.Disconnected) {
        this.audioRecorder.detach(guildId);

        if (this.recoveringGuilds.has(guildId)) return;
        this.recoveringGuilds.add(guildId);

        void this.enqueueGuild(
          guildId,
          () => this.recreateConnection(connection, guildId, channelId),
          'voice_connection_recreate_failed',
        );
      }

      if (newState.status === VoiceConnectionStatus.Destroyed) {
        void this.cleanupDestroyedConnection(guildId, channelId);
      }
    });

    connection.on('debug', (message) => {
      this.logInfo('voice_connection_debug', {
        guild_id: guildId,
        channel_id: channelId,
        message,
      });
    });

    connection.on('error', (error) => {
      this.logError('voice_connection_error', error);
    });
  }

  private async recreateConnection(connection: VoiceConnection, guildId: string, channelId: string): Promise<void> {
    try {
      await new Promise((resolve) => setTimeout(resolve, DISCONNECTED_RECOVERY_GRACE_MS));

      if (connection.state.status !== VoiceConnectionStatus.Disconnected) {
        this.logInfo('voice_connection_recreate_skipped', {
          guild_id: guildId,
          channel_id: channelId,
          status: connection.state.status,
        });
        return;
      }

      this.logInfo('voice_connection_recreate_requested', { guild_id: guildId, channel_id: channelId });
      this.nextJoinAttempt(guildId);
      connection.destroy();

      const assignment = this.assignments.get(channelId);
      if (!assignment) return;

      await this.switchTo(assignment, { forceDisconnected: true });
    } catch (error) {
      if (connection.state.status !== VoiceConnectionStatus.Destroyed) {
        connection.destroy();
      }

      throw error;
    } finally {
      this.recoveringGuilds.delete(guildId);
    }
  }

  private async cleanupDestroyedConnection(guildId: string, channelId: string): Promise<void> {
    if (this.activeChannels.get(guildId) !== channelId) return;

    this.audioRecorder.detach(guildId);
    this.activeChannels.delete(guildId);
    this.leaveTimers.delete(channelId);
    this.recoveringGuilds.delete(guildId);

    const sessionId = this.sessions.get(guildId);
    if (sessionId) {
      await this.api.endVoiceSession(sessionId).catch(() => undefined);
      this.sessions.delete(guildId);
    }
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
    const connection = getVoiceConnection(guildId);

    if (
      connection
      && [VoiceConnectionStatus.Destroyed, VoiceConnectionStatus.Disconnected].includes(connection.state.status)
    ) {
      return null;
    }

    const channelId = this.activeChannels.get(guildId) ?? connection?.joinConfig.channelId;
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

  private async preferredActiveAssignment(guildId: string, assignments: ChannelAssignment[]): Promise<ChannelAssignment | null> {
    const activeChannelId = this.activeChannels.get(guildId);
    const previous = activeChannelId
      ? assignments.find((assignment) => assignment.channel_discord_id === activeChannelId)
      : undefined;

    if (!previous) return this.firstActiveAssignment(assignments);

    const guild = this.client.guilds.cache.get(previous.guild_discord_id) ?? (await this.client.guilds.fetch(previous.guild_discord_id).catch(() => null));
    const channel = guild?.channels.cache.get(previous.channel_discord_id) ?? (await guild?.channels.fetch(previous.channel_discord_id).catch(() => null));

    if (channel?.isVoiceBased()) {
      const members = this.humanMembers(channel);
      this.logInfo('voice_previous_assignment_checked', {
        guild_id: previous.guild_discord_id,
        channel_id: previous.channel_discord_id,
        channel_name: channel.name,
        human_member_count: members.length,
      });

      if (members.length > 0) return previous;
    }

    return this.firstActiveAssignment(assignments);
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

  private nextJoinAttempt(guildId: string): number {
    const next = (this.joinAttempts.get(guildId) ?? 0) + 1;
    this.joinAttempts.set(guildId, next);

    return next;
  }

  private isCurrentJoinAttempt(guildId: string, attempt: number): boolean {
    return this.joinAttempts.get(guildId) === attempt;
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
