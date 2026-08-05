<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\GlobalSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class DiscordChannel extends Model
{
    use HasUuid;

    public const VOICE_TYPES = ['voice', 'stage'];

    protected $fillable = [
        'discord_guild_id',
        'discord_id',
        'name',
        'category_discord_id',
        'type',
        'user_limit',
        'is_monitored',
        'discord_bot_id',
        'buffer_seconds',
        'volume_analysis_enabled',
        'transcription_enabled',
        'retention_days',
        'moderation_config',
        'last_synced_at',
        'archived_at',
    ];

    protected $attributes = [
        'is_monitored' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_monitored' => 'boolean',
            'volume_analysis_enabled' => 'boolean',
            'transcription_enabled' => 'boolean',
            'moderation_config' => 'array',
            'last_synced_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $channel): void {
            $attributes = $channel->getAttributes();
            $defaults = app(GlobalSettings::class)->channelDefaults();

            if (! array_key_exists('buffer_seconds', $attributes)) {
                $channel->buffer_seconds = $defaults['buffer_seconds'];
            }

            if (! array_key_exists('retention_days', $attributes)) {
                $channel->retention_days = $defaults['retention_days'];
            }

            if (! array_key_exists('volume_analysis_enabled', $attributes)) {
                $channel->volume_analysis_enabled = $defaults['volume_analysis_enabled'];
            }

            if (! array_key_exists('transcription_enabled', $attributes)) {
                $channel->transcription_enabled = $defaults['transcription_enabled'];
            }
        });

        static::saving(function (self $channel): void {
            if ($channel->is_monitored && ! $channel->isVoiceBased()) {
                throw ValidationException::withMessages(['is_monitored' => 'Seuls les salons vocaux peuvent être surveillés.']);
            }
        });
    }

    public function scopeVoiceBased(Builder $query): Builder
    {
        return $query->whereIn('type', self::VOICE_TYPES);
    }

    public function scopeActiveOnDiscord(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isVoiceBased(): bool
    {
        return in_array($this->type, self::VOICE_TYPES, true);
    }

    public function guild(): BelongsTo
    {
        return $this->belongsTo(DiscordGuild::class, 'discord_guild_id');
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(DiscordBot::class, 'discord_bot_id');
    }

    public function assignment(): HasOne
    {
        return $this->hasOne(VoiceChannelAssignment::class);
    }

    public function voiceSessions(): HasMany
    {
        return $this->hasMany(VoiceSession::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(VoiceReport::class);
    }
}
