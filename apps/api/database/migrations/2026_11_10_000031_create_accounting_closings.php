<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_closings', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->ulid('period_id')->index();
            $t->ulid('active_period_id')->nullable()->unique();
            $t->ulid('retained_account_id');
            $t->string('status');
            $t->unsignedInteger('version');
            $t->unsignedInteger('period_version');
            $t->string('basis_hash', 64);
            $t->text('snapshot');
            $t->text('lines');
            $t->bigInteger('profit');
            $t->bigInteger('total_amount');
            $t->ulid('journal_id')->nullable()->unique();
            $t->ulid('reopening_journal_id')->nullable()->unique();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('note');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('accounting_closings')->exists()) {
            throw new RuntimeException('Riwayat closing tidak boleh dihapus.');
        }
        Schema::dropIfExists('accounting_closings');
    }
};
