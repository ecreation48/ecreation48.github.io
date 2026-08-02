<?php
namespace App\Http\Controllers;
use App\Models\VoiceAudioClip; use Illuminate\Http\Response; use Symfony\Component\HttpFoundation\BinaryFileResponse;
class VoiceAudioClipStreamController extends Controller { public function __invoke(VoiceAudioClip $voiceAudioClip): BinaryFileResponse|Response { $path=$voiceAudioClip->resolvedStoragePath(); if(!$path){return response('Fichier audio introuvable.',404);} return response()->file($path,['Content-Type'=>$voiceAudioClip->mime_type,'Content-Disposition'=>'inline; filename="'.$voiceAudioClip->id.'.wav"']); } }
