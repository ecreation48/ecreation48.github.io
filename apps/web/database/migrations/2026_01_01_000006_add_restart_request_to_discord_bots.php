<?php
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void {Schema::table('discord_bots',function(Blueprint $t){$t->timestampTz('restart_requested_at')->nullable()->after('last_error_at');});} public function down(): void {Schema::table('discord_bots',function(Blueprint $t){$t->dropColumn('restart_requested_at');});} };
