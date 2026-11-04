<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_allocations', fn (Blueprint $t) => $t->json('commercial_origin_snapshot')->nullable());
        Schema::table('stock_entries', fn (Blueprint $t) => $t->index(['inventory_batch_id', 'created_at'], 'stock_entries_batch_time_idx'));
    }

    public function down(): void
    {
        if (DB::table('sale_allocations')->whereNotNull('commercial_origin_snapshot')->exists()) {
            throw new RuntimeException('Commercial provenance history requires forward migration.');
        }
        Schema::table('stock_entries', fn (Blueprint $t) => $t->dropIndex('stock_entries_batch_time_idx'));
        Schema::table('sale_allocations', fn (Blueprint $t) => $t->dropColumn('commercial_origin_snapshot'));
    }
};
