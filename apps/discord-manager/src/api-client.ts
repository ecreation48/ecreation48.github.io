export interface BotSummary {id:string;name:string;client_id:string;connection_status:string;restart_requested_at?:string|null}
export interface ChannelAssignment {channel_id:string;channel_discord_id:string;guild_id:string;guild_discord_id:string;buffer_seconds:number;volume_analysis_enabled:boolean;transcription_enabled:boolean;retention_days:number;report_notification_channel_discord_id?:string|null;report_mention_role_discord_ids?:string[];moderator_role_discord_id?:string|null;auto_detection_enabled?:boolean;auto_detection_priority?:number}
export interface BotAssignments {bot_id:string;channels:ChannelAssignment[]}
export interface DiscordChannelSyncPayload {guild?:{name?:string;owner_id?:string};channels:{id:string;name:string;type:number;parent_id?:string|null;user_limit?:number}[];roles?:{id:string;name:string;position?:number}[]}
export interface VoiceSessionMemberPayload {discord_user_id:string;display_name?:string}
export interface VoiceSessionPayload {discord_bot_id:string;discord_guild_id:string;discord_channel_id:string;member_count:number;members?:VoiceSessionMemberPayload[]}
export interface VoiceEventPayload {guild_discord_id:string;channel_discord_id?:string;discord_user_id?:string;type:'voice_join'|'voice_leave'|'voice_move'|'voice_mute'|'voice_unmute'|'voice_deafen'|'voice_undeafen'|'screen_share_start'|'screen_share_stop'|'bot_join'|'bot_leave';payload?:Record<string,unknown>;occurred_at?:string}
export interface VoiceReportPayload {guild_discord_id:string;channel_discord_id:string;reported_user_discord_id:string;reporter_user_discord_id:string;reason:string;comment?:string}
export interface AutomaticVoiceReportPayload extends VoiceReportPayload {source:'blocked_word';detection_confidence?:number|null;detection_metadata?:Record<string,unknown>}
export interface AudioClipPayload {voice_report_id:string;guild_discord_id:string;channel_discord_id:string;reported_user_discord_id:string;storage_path:string;mime_type:string;size_bytes:number;duration_seconds:number;captured_from:string;captured_until:string}
export interface TranscriptSegmentPayload {start_seconds:number;end_seconds:number;text:string;confidence?:number|null}
export interface TranscriptPayload {voice_report_id:string;voice_audio_clip_id?:string|null;reported_user_discord_id?:string|null;status:'pending'|'processing'|'completed'|'failed'|'skipped';text?:string|null;language?:string|null;confidence?:number|null;engine?:string|null;duration_ms?:number|null;error_message?:string|null;segments?:TranscriptSegmentPayload[]}
export interface TranscriptJob {id:string;voice_report_id:string;voice_audio_clip_id:string;reported_user_discord_id:string;storage_path:string}
export interface ModerationActionJob {id:string;guild_discord_id:string;target_user_discord_id:string;type:'warn'|'disconnect'|'timeout'|'move'|'mute'|'kick'|'ban'|'note'|'dismiss'|'delete_audio';duration_seconds:number|null;reason:string|null}
export interface VoiceBroadcastJob {id:string;guild_discord_id:string;channel_discord_id:string;type:'file';storage_path:string|null;mime_type:string|null;title:string|null}
export interface ForbiddenWord {id:string;word:string;normalized_word:string;severity:string}
interface ApiEnvelope<T>{data:T}

export class ApiClient {
  constructor(private readonly baseUrl:string,private readonly token:string,private readonly timeoutMs=Number(process.env.INTERNAL_API_TIMEOUT_MS??30_000)){}

  private async request<T>(path:string,init:RequestInit={}):Promise<T>{
    const url=`${this.baseUrl}${path}`;

    try {
      const response=await fetch(url,{...init,headers:{accept:'application/json',authorization:`Bearer ${this.token}`,'content-type':'application/json',...init.headers},redirect:'manual',signal:AbortSignal.timeout(this.timeoutMs)});
      if(!response.ok)throw new Error(`Internal API ${response.status} on ${path}: ${await response.text().catch(()=>'')}`);
      return (await response.json() as ApiEnvelope<T>).data;
    } catch (error) {
      const message=error instanceof Error?error.message:'unknown';
      const name=error instanceof Error?error.name:'Error';
      throw new Error(`${name} on ${path} (${url}): ${message}`);
    }
  }

  listBots():Promise<BotSummary[]>{return this.request('/bots');}
  credentials(id:string):Promise<{id:string;token:string;client_id:string}>{return this.request(`/bots/${id}/credentials`);}
  assignments(id:string):Promise<BotAssignments>{return this.request(`/bots/${id}/assignments`);}
  syncGuildChannels(botId:string,guildDiscordId:string,payload:DiscordChannelSyncPayload):Promise<{accepted:boolean}>{return this.request(`/bots/${botId}/guilds/${guildDiscordId}/channels`,{method:'POST',body:JSON.stringify(payload)});}
  createVoiceSession(payload:VoiceSessionPayload):Promise<{id:string;status:string}>{return this.request('/voice-sessions',{method:'POST',body:JSON.stringify(payload)});}
  voiceSessionHeartbeat(id:string,payload:{member_count:number;status?:'active'|'empty'|'error';members?:VoiceSessionMemberPayload[]}):Promise<{accepted:boolean}>{return this.request(`/voice-sessions/${id}/heartbeat`,{method:'POST',body:JSON.stringify(payload)});}
  endVoiceSession(id:string):Promise<{accepted:boolean}>{return this.request(`/voice-sessions/${id}`,{method:'DELETE'});}
  createVoiceEvent(payload:VoiceEventPayload):Promise<{id:string}>{return this.request('/events',{method:'POST',body:JSON.stringify(payload)});}
  createVoiceReport(payload:VoiceReportPayload|AutomaticVoiceReportPayload):Promise<{id:string;status:string}>{return this.request('/reports',{method:'POST',body:JSON.stringify(payload)});}
  createAudioClip(payload:AudioClipPayload):Promise<{id:string}>{return this.request('/audio-clips',{method:'POST',body:JSON.stringify(payload)});}
  transcripts(limit=5):Promise<TranscriptJob[]>{return this.request(`/transcripts?limit=${limit}`);}
  createTranscript(payload:TranscriptPayload):Promise<{id:string;status:string}>{return this.request('/transcripts',{method:'POST',body:JSON.stringify(payload)});}
  forbiddenWords():Promise<ForbiddenWord[]>{return this.request('/forbidden-words');}
  moderationActions(botId:string):Promise<ModerationActionJob[]>{return this.request(`/moderation-actions?bot_id=${encodeURIComponent(botId)}`);}
  updateModerationAction(id:string,payload:{result:'success'|'failed'|'recorded';error_message?:string}):Promise<{accepted:boolean}>{return this.request(`/moderation-actions/${id}`,{method:'POST',body:JSON.stringify(payload)});}
  voiceBroadcasts(botId:string):Promise<VoiceBroadcastJob[]>{return this.request(`/voice-broadcasts?bot_id=${encodeURIComponent(botId)}`);}
  updateVoiceBroadcast(id:string,payload:{status:'playing'|'success'|'failed';error_message?:string}):Promise<{accepted:boolean}>{return this.request(`/voice-broadcasts/${id}`,{method:'POST',body:JSON.stringify(payload)});}
  heartbeat(id:string,payload:Record<string,string>):Promise<{accepted:boolean}>{return this.request(`/bots/${id}/heartbeat`,{method:'POST',body:JSON.stringify(payload)});}
}
