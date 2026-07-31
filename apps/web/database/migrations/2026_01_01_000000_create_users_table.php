<?php
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void{Schema::create('users',function(Blueprint $t){$t->uuid('id')->primary();$t->string('name');$t->string('email')->unique();$t->timestampTz('email_verified_at')->nullable();$t->string('password');$t->string('role')->default('viewer')->index();$t->rememberToken();$t->timestampsTz();});}public function down():void{Schema::dropIfExists('users');}};
