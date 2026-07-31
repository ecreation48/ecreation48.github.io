<?php
namespace App\Models;
use App\Models\Concerns\HasUuid; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\HasMany;
class WorkerInstance extends Model { use HasUuid; protected $fillable=['name','type','hostname','status','version','last_heartbeat_at','metadata']; protected function casts(): array{return ['last_heartbeat_at'=>'datetime','metadata'=>'array'];} public function bots(): HasMany{return $this->hasMany(DiscordBot::class);} }
