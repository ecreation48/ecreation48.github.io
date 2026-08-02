<?php
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void {
 if(!Schema::hasTable('discord_members')){Schema::create('discord_members',function(Blueprint $t){$t->uuid('id')->primary();$t->foreignUuid('discord_guild_id')->constrained()->cascadeOnDelete();$t->string('discord_id');$t->string('display_name');$t->text('avatar')->nullable();$t->boolean('is_owner')->default(false)->index();$t->timestampsTz();$t->unique(['discord_guild_id','discord_id']);});}
 DB::table('discord_channels')->where('type','voice')->update(['is_monitored'=>true]);
 } public function down(): void {} };
