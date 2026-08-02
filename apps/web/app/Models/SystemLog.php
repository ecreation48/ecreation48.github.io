<?php
namespace App\Models;
use App\Models\Concerns\HasUuid; use Illuminate\Database\Eloquent\Model;
class SystemLog extends Model { use HasUuid; protected $fillable=['level','source','event','message','context','occurred_at']; protected function casts(): array{return ['context'=>'array','occurred_at'=>'datetime'];} }
