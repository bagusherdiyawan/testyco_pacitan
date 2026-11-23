<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_recipes', function (Blueprint $t) {
            $t->unsignedInteger('portion_equivalent')->default(0);
        });
    }

    public function down(): void
    {
        if (DB::table('product_recipes')->where('portion_equivalent', '>', 0)->exists()) {
            throw new RuntimeException('Resep paket dengan padanan porsi tidak boleh dihapus.');
        }
        Schema::table('product_recipes', function (Blueprint $t) {
            $t->dropColumn('portion_equivalent');
        });
    }
};
