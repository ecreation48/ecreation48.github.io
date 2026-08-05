<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ForbiddenWord;
use Illuminate\Http\JsonResponse;

class ForbiddenWordController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => ForbiddenWord::query()
                ->where('is_active', true)
                ->orderByDesc('severity')
                ->orderBy('word')
                ->get(['id', 'word', 'normalized_word', 'severity'])
                ->map(fn (ForbiddenWord $word): array => [
                    'id' => $word->id,
                    'word' => $word->word,
                    'normalized_word' => $word->normalized_word,
                    'severity' => $word->severity,
                ])
                ->values(),
        ]);
    }
}
