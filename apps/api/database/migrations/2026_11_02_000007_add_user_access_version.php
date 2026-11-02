<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->unsignedInteger('access_version')->default(1));
    }

    public function down(): void
    {
        if (DB::table('users')->where('access_version', '>', 1)->exists() || DB::table('operation_receipts')->where('scope', 'like', 'user.reactivate:%')->exists()) {
            throw new RuntimeException('Rollback versi akses ditolak setelah digunakan; gunakan perbaikan maju atau restore yang ditinjau.');
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('access_version'));
    }
};
