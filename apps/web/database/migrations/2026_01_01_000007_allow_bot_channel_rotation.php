<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if ($this->hasIndex('one_voice_connection_per_bot_guild')) {
            Schema::table('discord_channels', function (Blueprint $table): void {
                $table->dropUnique('one_voice_connection_per_bot_guild');
            });
        }

        if (! $this->hasIndex('discord_channels_guild_bot_index')) {
            Schema::table('discord_channels', function (Blueprint $table): void {
                $table->index(['discord_guild_id', 'discord_bot_id'], 'discord_channels_guild_bot_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('discord_channels', function (Blueprint $table): void {
            if ($this->hasIndex('discord_channels_guild_bot_index')) {
                $table->dropIndex('discord_channels_guild_bot_index');
            }
            $table->unique(['discord_guild_id', 'discord_bot_id'], 'one_voice_connection_per_bot_guild');
        });
    }

    private function hasIndex(string $name): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('discord_channels')"))->contains(fn ($index): bool => ($index->name ?? null) === $name);
        }

        return collect(Schema::getIndexes('discord_channels'))->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }
};
