<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_changes', fn (Blueprint $t) => $t->string('kind', 20)->default('reduction'));
        Schema::table('supply_order_lines', fn (Blueprint $t) => $t->dropUnique(['supply_order_id', 'supplier_product_id']));
    }

    public function down(): void
    {
        if (DB::table('order_changes')->where('kind', 'revision')->exists()) {
            throw new RuntimeException('Rollback riwayat revisi terpakai ditolak.');
        }Schema::table('order_changes', fn (Blueprint $t) => $t->dropColumn('kind'));
        Schema::table('supply_order_lines', fn (Blueprint $t) => $t->unique(['supply_order_id', 'supplier_product_id']));
    }
};
