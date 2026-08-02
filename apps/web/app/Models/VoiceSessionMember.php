<?php
namespace App\Models;
use App\Models\Concerns\HasUuid; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class VoiceSessionMember extends Model { use HasUuid; protected $fillable=['voice_session_id','discord_user_id','display_name','joined_at','left_at']; protected function casts(): array{return ['joined_at'=>'datetime','left_at'=>'datetime'];} public function session(): BelongsTo{return $this->belongsTo(VoiceSession::class,'voice_session_id');} }
