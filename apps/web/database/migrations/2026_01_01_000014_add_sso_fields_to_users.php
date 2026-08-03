<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('sso_provider')->nullable()->index();
            $table->string('sso_provider_id')->nullable()->index();
            $table->timestampTz('last_login_at')->nullable();
            $table->unique(['sso_provider', 'sso_provider_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['sso_provider', 'sso_provider_id']);
            $table->dropColumn(['sso_provider', 'sso_provider_id', 'last_login_at']);
        });
    }
};
