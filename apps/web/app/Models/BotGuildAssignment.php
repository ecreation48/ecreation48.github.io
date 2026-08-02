<?php
namespace App\Models;
use App\Models\Concerns\HasUuid; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class BotGuildAssignment extends Model { use HasUuid; protected $fillable=['discord_bot_id','discord_guild_id','is_active','config']; protected function casts(): array{return ['is_active'=>'boolean','config'=>'array'];} public function bot(): BelongsTo{return $this->belongsTo(DiscordBot::class,'discord_bot_id');} public function guild(): BelongsTo{return $this->belongsTo(DiscordGuild::class,'discord_guild_id');} }
