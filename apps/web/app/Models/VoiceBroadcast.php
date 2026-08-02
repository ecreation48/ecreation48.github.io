<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceBroadcast extends Model
{
    use HasUuid;

    protected $fillable = [
        'discord_bot_id',
        'discord_guild_id',
        'discord_channel_id',
        'type',
        'status',
        'storage_path',
        'mime_type',
        'title',
        'error_message',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(DiscordBot::class, 'discord_bot_id');
    }

    public function guild(): BelongsTo
    {
        return $this->belongsTo(DiscordGuild::class, 'discord_guild_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(DiscordChannel::class, 'discord_channel_id');
    }

    public function resolvedStoragePath(): ?string
    {
        if (! $this->storage_path) {
            return null;
        }

        $path = storage_path('app/'.$this->storage_path);

        return is_file($path) ? $path : null;
    }
}
