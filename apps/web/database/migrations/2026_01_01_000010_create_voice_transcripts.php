<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_transcripts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('voice_report_id')->constrained('voice_reports')->cascadeOnDelete();
            $table->foreignUuid('voice_audio_clip_id')->nullable()->constrained('voice_audio_clips')->nullOnDelete();
            $table->string('reported_user_discord_id')->nullable();
            $table->string('status')->default('pending');
            $table->longText('text')->nullable();
            $table->string('language', 20)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('engine')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['voice_report_id', 'status']);
        });

        Schema::create('voice_transcript_segments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('voice_transcript_id')->constrained('voice_transcripts')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->decimal('start_seconds', 8, 3)->default(0);
            $table->decimal('end_seconds', 8, 3)->default(0);
            $table->text('text');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->timestamps();

            $table->index(['voice_transcript_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_transcript_segments');
        Schema::dropIfExists('voice_transcripts');
    }
};
