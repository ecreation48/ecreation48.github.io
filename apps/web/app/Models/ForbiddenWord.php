<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ForbiddenWord extends Model
{
    use HasUuid;

    protected $fillable = [
        'word',
        'normalized_word',
        'severity',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ForbiddenWord $word): void {
            $word->word = trim($word->word);
            $word->normalized_word = self::normalize($word->word);
        });
    }

    public static function normalize(string $word): string
    {
        return Str::of($word)->lower()->squish()->ascii()->toString();
    }
}
