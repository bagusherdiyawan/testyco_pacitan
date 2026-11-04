<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partnership_contracts', function (Blueprint $t) {
            $t->string('attachment_path')->nullable();
            $t->string('attachment_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('partnership_contracts')->whereNotNull('attachment_path')->exists()) {
            throw new RuntimeException('Rollback lampiran kontrak terpakai ditolak.');
        }Schema::table('partnership_contracts', fn (Blueprint $t) => $t->dropColumn(['attachment_path', 'attachment_sha256']));
    }
};
