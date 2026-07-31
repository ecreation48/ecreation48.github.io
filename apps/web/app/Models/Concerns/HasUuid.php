<?php
namespace App\Models\Concerns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
trait HasUuid { use HasUuids; public function getKeyType(): string { return 'string'; } public function getIncrementing(): bool { return false; } }
