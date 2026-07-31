<?php
namespace App\Models;
use App\Models\Concerns\HasUuid; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo; use Illuminate\Database\Eloquent\Relations\HasMany;
class DiscordGuild extends Model { use HasUuid; protected $fillable=['discord_id','name','icon','owner_discord_id','discord_bot_id','is_active','moderation_config']; protected function casts(): array{return ['is_active'=>'boolean','moderation_config'=>'array'];} public function bot(): BelongsTo{return $this->belongsTo(DiscordBot::class,'discord_bot_id');} public function channels(): HasMany{return $this->hasMany(DiscordChannel::class);} }
