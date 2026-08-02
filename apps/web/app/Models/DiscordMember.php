<?php
namespace App\Models;
use App\Models\Concerns\HasUuid; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class DiscordMember extends Model { use HasUuid; protected $fillable=['discord_guild_id','discord_id','display_name','avatar','is_owner']; protected function casts(): array{return ['is_owner'=>'boolean'];} public function guild(): BelongsTo{return $this->belongsTo(DiscordGuild::class,'discord_guild_id');} }
