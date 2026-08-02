<?php
use App\Http\Controllers\Api\V1\BotController;
use App\Http\Controllers\Api\V1\ModerationActionController;
use App\Http\Controllers\Api\V1\VoiceEventController;
use App\Http\Controllers\Api\V1\VoiceAudioClipController;
use App\Http\Controllers\Api\V1\VoiceReportController;
use App\Http\Controllers\Api\V1\VoiceSessionController;
use App\Http\Controllers\Api\V1\VoiceBroadcastController;
use App\Http\Controllers\Api\V1\VoiceTranscriptController;
use Illuminate\Support\Facades\Route;
Route::prefix('v1/internal')->middleware(['service','throttle:120,1'])->group(function (): void {
    Route::get('/bots', [BotController::class, 'index']);
    Route::get('/bots/{discordBot}/credentials', [BotController::class, 'credentials']);
    Route::get('/bots/{discordBot}/assignments', [BotController::class, 'assignments']);
    Route::post('/bots/{discordBot}/guilds/{guildDiscordId}/channels', [BotController::class, 'syncGuildChannels']);
    Route::post('/bots/{discordBot}/heartbeat', [BotController::class, 'heartbeat']);
    Route::post('/voice-sessions', [VoiceSessionController::class, 'store']);
    Route::post('/voice-sessions/{voiceSession}/heartbeat', [VoiceSessionController::class, 'heartbeat']);
    Route::delete('/voice-sessions/{voiceSession}', [VoiceSessionController::class, 'destroy']);
    Route::post('/events', [VoiceEventController::class, 'store']);
    Route::post('/reports', [VoiceReportController::class, 'store']);
    Route::post('/audio-clips', [VoiceAudioClipController::class, 'store']);
    Route::get('/transcripts', [VoiceTranscriptController::class, 'index']);
    Route::post('/transcripts', [VoiceTranscriptController::class, 'store']);
    Route::get('/voice-broadcasts', [VoiceBroadcastController::class, 'index']);
    Route::post('/voice-broadcasts/{voiceBroadcast}', [VoiceBroadcastController::class, 'update']);
    Route::get('/moderation-actions', [ModerationActionController::class, 'index']);
    Route::post('/moderation-actions/{moderationAction}', [ModerationActionController::class, 'update']);
});
