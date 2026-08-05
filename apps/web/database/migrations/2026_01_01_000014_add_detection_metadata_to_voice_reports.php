<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_reports', function (Blueprint $table): void {
            $table->string('source')->default('manual')->index()->after('comment');
            $table->decimal('detection_confidence', 5, 4)->nullable()->after('source');
            $table->jsonb('detection_metadata')->default('{}')->after('detection_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('voice_reports', function (Blueprint $table): void {
            $table->dropColumn(['source', 'detection_confidence', 'detection_metadata']);
        });
    }
};
