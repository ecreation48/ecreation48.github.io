<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoiceTranscript extends Model
{
    use HasUuid;

    protected $fillable = [
        'voice_report_id',
        'voice_audio_clip_id',
        'reported_user_discord_id',
        'status',
        'text',
        'language',
        'confidence',
        'engine',
        'duration_ms',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(VoiceReport::class, 'voice_report_id');
    }

    public function audioClip(): BelongsTo
    {
        return $this->belongsTo(VoiceAudioClip::class, 'voice_audio_clip_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(VoiceTranscriptSegment::class)->orderBy('position');
    }
}
