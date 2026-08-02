<?php
namespace App\Models;
use App\Models\Concerns\HasUuid; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class DiscordRole extends Model { use HasUuid; protected $fillable=['discord_guild_id','discord_id','name','position','is_moderator']; protected function casts(): array{return ['position'=>'integer','is_moderator'=>'boolean'];} public function guild(): BelongsTo{return $this->belongsTo(DiscordGuild::class,'discord_guild_id');} }
