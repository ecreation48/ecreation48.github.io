<?php
namespace App\Models;
use App\Models\Concerns\HasUuid; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class DiscordChannel extends Model { use HasUuid; protected $fillable=['discord_guild_id','discord_id','name','category_discord_id','type','user_limit','is_monitored','discord_bot_id','buffer_seconds','volume_analysis_enabled','transcription_enabled','retention_days','moderation_config']; protected function casts(): array{return ['is_monitored'=>'boolean','volume_analysis_enabled'=>'boolean','transcription_enabled'=>'boolean','moderation_config'=>'array'];} public function guild(): BelongsTo{return $this->belongsTo(DiscordGuild::class);} public function bot(): BelongsTo{return $this->belongsTo(DiscordBot::class,'discord_bot_id');} }
