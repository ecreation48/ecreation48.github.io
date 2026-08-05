<?php
namespace App\Models;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
class DiscordBot extends Model {
 use HasFactory, HasUuid, SoftDeletes;
 protected $fillable=['name','token','client_id','is_active','connection_status','last_connected_at','last_error_at','restart_requested_at','error_message','worker_instance_id','default_config'];
 protected $hidden=['token'];
 protected function casts(): array { return ['token'=>'encrypted','is_active'=>'boolean','last_connected_at'=>'datetime','last_error_at'=>'datetime','restart_requested_at'=>'datetime','default_config'=>'array']; }
 public function guilds(): HasMany { return $this->hasMany(DiscordGuild::class); }
 public function guildAssignments(): HasMany { return $this->hasMany(BotGuildAssignment::class); }
 public function channelAssignments(): HasMany { return $this->hasMany(VoiceChannelAssignment::class); }
 public function voiceSessions(): HasMany { return $this->hasMany(VoiceSession::class); }
 public function activeVoiceSessions(): HasMany { return $this->hasMany(VoiceSession::class)->whereNull('ended_at')->where('last_activity_at','>=',now()->subMinutes(2))->latest('last_activity_at')->latest('started_at'); }
 public function workerInstance(): BelongsTo { return $this->belongsTo(WorkerInstance::class); }
}
