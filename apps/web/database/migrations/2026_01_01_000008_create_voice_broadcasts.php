<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('voice_broadcasts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('discord_bot_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('discord_guild_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('discord_channel_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('file')->index();
            $table->string('status')->default('pending')->index();
            $table->text('storage_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('title')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('queued_at')->index();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_broadcasts');
    }
};
