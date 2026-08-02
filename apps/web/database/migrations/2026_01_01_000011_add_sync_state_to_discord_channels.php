<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discord_channels', function (Blueprint $table): void {
            $table->timestampTz('last_synced_at')->nullable()->after('moderation_config')->index();
            $table->timestampTz('archived_at')->nullable()->after('last_synced_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('discord_channels', function (Blueprint $table): void {
            $table->dropColumn(['last_synced_at', 'archived_at']);
        });
    }
};
