<?php
use App\Http\Controllers\VoiceAudioClipStreamController;
use App\Http\Controllers\LiveVoiceChannelStreamController;
use Illuminate\Support\Facades\Route;
Route::view('/', 'welcome');
Route::get('/admin/voice-channels/{discordChannel}/live', LiveVoiceChannelStreamController::class)->middleware('auth')->name('admin.voice-channels.live');
Route::get('/admin/audio-clips/{voiceAudioClip}/stream', VoiceAudioClipStreamController::class)->middleware('auth')->name('admin.audio-clips.stream');
