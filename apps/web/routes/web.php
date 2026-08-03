<?php
use App\Http\Controllers\VoiceAudioClipStreamController;
use App\Http\Controllers\ForbiddenWordQuickStoreController;
use App\Http\Controllers\Auth\AuthentikSsoController;
use App\Http\Controllers\LiveVoiceChannelStreamController;
use Illuminate\Support\Facades\Route;
Route::view('/', 'welcome');
Route::get('/auth/sso/redirect', [AuthentikSsoController::class, 'redirect'])->middleware('guest')->name('auth.sso.redirect');
Route::get('/auth/sso/callback', [AuthentikSsoController::class, 'callback'])->middleware('guest')->name('auth.sso.callback');
Route::get('/admin/voice-channels/{discordChannel}/live', LiveVoiceChannelStreamController::class)->middleware('auth')->name('admin.voice-channels.live');
Route::get('/admin/audio-clips/{voiceAudioClip}/stream', VoiceAudioClipStreamController::class)->middleware('auth')->name('admin.audio-clips.stream');
Route::post('/admin/forbidden-words/quick-store', ForbiddenWordQuickStoreController::class)->middleware('auth')->name('admin.forbidden-words.quick-store');
