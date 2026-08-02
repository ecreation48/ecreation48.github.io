<?php
namespace App\Models;
use App\Models\Concerns\HasUuid; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class VoiceChannelAssignment extends Model { use HasUuid; protected $fillable=['discord_channel_id','discord_bot_id','is_active','buffer_seconds','volume_analysis_enabled','transcription_enabled','retention_days','moderation_config']; protected function casts(): array{return ['is_active'=>'boolean','volume_analysis_enabled'=>'boolean','transcription_enabled'=>'boolean','moderation_config'=>'array'];} public function channel(): BelongsTo{return $this->belongsTo(DiscordChannel::class,'discord_channel_id');} public function bot(): BelongsTo{return $this->belongsTo(DiscordBot::class,'discord_bot_id');} }
