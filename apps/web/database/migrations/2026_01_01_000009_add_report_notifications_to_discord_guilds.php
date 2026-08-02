<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discord_guilds', function (Blueprint $table): void {
            $table->string('report_notification_channel_discord_id')->nullable()->after('report_channel_discord_id');
            $table->json('report_mention_role_discord_ids')->nullable()->after('report_notification_channel_discord_id');
        });
    }

    public function down(): void
    {
        Schema::table('discord_guilds', function (Blueprint $table): void {
            $table->dropColumn(['report_notification_channel_discord_id', 'report_mention_role_discord_ids']);
        });
    }
};
