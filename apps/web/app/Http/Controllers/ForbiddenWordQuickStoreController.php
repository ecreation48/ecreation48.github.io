<?php

namespace App\Http\Controllers;

use App\Models\ForbiddenWord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ForbiddenWordQuickStoreController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'word' => ['required', 'string', 'max:120'],
            'severity' => ['nullable', 'string', 'in:low,medium,high,critical'],
        ]);

        ForbiddenWord::query()->updateOrCreate(
            ['normalized_word' => ForbiddenWord::normalize($data['word'])],
            [
                'word' => trim($data['word']),
                'severity' => $data['severity'] ?? 'medium',
                'is_active' => true,
            ],
        );

        return back()->with('status', 'Mot interdit ajouté.');
    }
}
