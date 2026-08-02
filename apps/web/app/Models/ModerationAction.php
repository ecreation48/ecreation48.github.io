<?php
namespace App\Models;
use App\Models\Concerns\HasUuid; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ModerationAction extends Model { use HasUuid; protected $fillable=['voice_report_id','discord_guild_id','moderator_discord_id','target_user_discord_id','type','duration_seconds','reason','result','error_message','actioned_at']; protected function casts(): array{return ['duration_seconds'=>'integer','actioned_at'=>'datetime'];} public function report(): BelongsTo{return $this->belongsTo(VoiceReport::class,'voice_report_id');} public function guild(): BelongsTo{return $this->belongsTo(DiscordGuild::class,'discord_guild_id');} }
