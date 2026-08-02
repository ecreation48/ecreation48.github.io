<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\VoiceAudioClip;
use App\Models\VoiceReport;
use App\Models\VoiceTranscript;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceTranscriptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = min((int) $request->integer('limit', 5), 20);

        $staleProcessingBefore = now()->subMinutes(10);

        $transcripts = VoiceTranscript::query()
            ->with('audioClip')
            ->where(function ($query) use ($staleProcessingBefore): void {
                $query
                    ->where('status', 'pending')
                    ->orWhere(function ($query) use ($staleProcessingBefore): void {
                        $query
                            ->where('status', 'processing')
                            ->where('started_at', '<=', $staleProcessingBefore);
                    });
            })
            ->oldest()
            ->limit($limit)
            ->get()
            ->filter(fn (VoiceTranscript $transcript): bool => $transcript->audioClip !== null)
            ->map(fn (VoiceTranscript $transcript): array => [
                'id' => $transcript->id,
                'voice_report_id' => $transcript->voice_report_id,
                'voice_audio_clip_id' => $transcript->voice_audio_clip_id,
                'reported_user_discord_id' => $transcript->reported_user_discord_id,
                'storage_path' => $transcript->audioClip?->storage_path,
            ])
            ->values();

        return response()->json(['data' => $transcripts]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'voice_report_id' => 'required|uuid|exists:voice_reports,id',
            'voice_audio_clip_id' => 'nullable|uuid|exists:voice_audio_clips,id',
            'reported_user_discord_id' => 'nullable|string|max:255',
            'status' => 'required|in:pending,processing,completed,failed,skipped',
            'text' => 'nullable|string|max:200000',
            'language' => 'nullable|string|max:20',
            'confidence' => 'nullable|numeric|min:0|max:1',
            'engine' => 'nullable|string|max:255',
            'duration_ms' => 'nullable|integer|min:0|max:86400000',
            'error_message' => 'nullable|string|max:4000',
            'segments' => 'array',
            'segments.*.start_seconds' => 'required_with:segments|numeric|min:0|max:86400',
            'segments.*.end_seconds' => 'required_with:segments|numeric|min:0|max:86400',
            'segments.*.text' => 'required_with:segments|string|max:10000',
            'segments.*.confidence' => 'nullable|numeric|min:0|max:1',
        ]);

        $report = VoiceReport::query()->findOrFail($data['voice_report_id']);
        $clip = isset($data['voice_audio_clip_id']) ? VoiceAudioClip::query()->find($data['voice_audio_clip_id']) : null;

        $transcript = VoiceTranscript::query()->updateOrCreate(
            [
                'voice_report_id' => $report->id,
                'voice_audio_clip_id' => $clip?->id,
                'reported_user_discord_id' => $data['reported_user_discord_id'] ?? $clip?->reported_user_discord_id,
            ],
            [
                'status' => $data['status'],
                'text' => $data['text'] ?? null,
                'language' => $data['language'] ?? null,
                'confidence' => $data['confidence'] ?? null,
                'engine' => $data['engine'] ?? null,
                'duration_ms' => $data['duration_ms'] ?? null,
                'error_message' => $data['error_message'] ?? null,
                'started_at' => in_array($data['status'], ['processing', 'completed', 'failed'], true) ? now() : null,
                'completed_at' => in_array($data['status'], ['completed', 'failed', 'skipped'], true) ? now() : null,
            ],
        );

        $transcript->segments()->delete();

        foreach (($data['segments'] ?? []) as $index => $segment) {
            $transcript->segments()->create([
                'position' => $index,
                'start_seconds' => $segment['start_seconds'],
                'end_seconds' => $segment['end_seconds'],
                'text' => $segment['text'],
                'confidence' => $segment['confidence'] ?? null,
            ]);
        }

        return response()->json(['data' => ['id' => $transcript->id, 'status' => $transcript->status]], 201);
    }
}
