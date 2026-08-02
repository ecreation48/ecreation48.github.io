<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceTranscriptSegment extends Model
{
    use HasUuid;

    protected $fillable = [
        'voice_transcript_id',
        'position',
        'start_seconds',
        'end_seconds',
        'text',
        'confidence',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'start_seconds' => 'float',
            'end_seconds' => 'float',
            'confidence' => 'float',
        ];
    }

    public function transcript(): BelongsTo
    {
        return $this->belongsTo(VoiceTranscript::class, 'voice_transcript_id');
    }
}
