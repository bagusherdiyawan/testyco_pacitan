<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_mfa', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained('users');
            $t->text('encrypted_secret');
            $t->json('recovery_hashes');
            $t->string('status');
            $t->unsignedInteger('version');
            $t->bigInteger('last_counter')->default(-1);
            $t->timestamp('created_at');
            $t->timestamp('enabled_at')->nullable();
        });
        Schema::create('login_challenges', function (Blueprint $t) {
            $t->string('token_hash', 64)->primary();
            $t->foreignId('user_id')->constrained();
            $t->unsignedInteger('access_version');
            $t->string('device_name', 80);
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('user_mfa')->exists()) {
            throw new RuntimeException('MFA enrollment must not be removed by rollback.');
        }Schema::dropIfExists('login_challenges');
        Schema::dropIfExists('user_mfa');
    }
};
