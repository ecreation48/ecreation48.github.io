<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\VoiceBroadcast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceBroadcastController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bot_id' => 'required|uuid',
        ]);

        $broadcasts = VoiceBroadcast::query()
            ->with(['guild', 'channel'])
            ->where('discord_bot_id', $data['bot_id'])
            ->where('status', 'pending')
            ->oldest('queued_at')
            ->limit(5)
            ->get()
            ->map(fn (VoiceBroadcast $broadcast): array => [
                'id' => $broadcast->id,
                'guild_discord_id' => $broadcast->guild?->discord_id,
                'channel_discord_id' => $broadcast->channel?->discord_id,
                'type' => $broadcast->type,
                'storage_path' => $broadcast->resolvedStoragePath(),
                'mime_type' => $broadcast->mime_type,
                'title' => $broadcast->title,
            ]);

        return response()->json(['data' => $broadcasts]);
    }

    public function update(Request $request, VoiceBroadcast $voiceBroadcast): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:playing,success,failed',
            'error_message' => 'nullable|string|max:2000',
        ]);

        $voiceBroadcast->update([
            'status' => $data['status'],
            'error_message' => $data['error_message'] ?? null,
            'started_at' => $data['status'] === 'playing' ? now() : $voiceBroadcast->started_at,
            'finished_at' => in_array($data['status'], ['success', 'failed'], true) ? now() : $voiceBroadcast->finished_at,
        ]);

        return response()->json(['data' => ['accepted' => true]]);
    }
}
