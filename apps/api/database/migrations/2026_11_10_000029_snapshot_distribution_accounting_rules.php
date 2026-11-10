<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_wholesale_policies', function (Blueprint $t) {
            $t->string('ownership_basis')->nullable();
            $t->string('provisional_basis')->nullable();
            $t->string('invoice_variance_basis')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('accounting_wholesale_policies')->whereNotNull('ownership_basis')->exists()) {
            throw new RuntimeException('Snapshot aturan distribusi yang digunakan tidak boleh dihapus.');
        }
        Schema::table('accounting_wholesale_policies', fn (Blueprint $t) => $t->dropColumn(['ownership_basis', 'provisional_basis', 'invoice_variance_basis']));
    }
};
