<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_minimums', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('store_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('minimum_pieces')->nullable();
            $t->unsignedInteger('version');
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->timestamps();
            $t->unique(['store_id', 'product_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_minimums')->exists()) {
            throw new LogicException('Ambang telah digunakan; gunakan migrasi maju untuk menjaga riwayat konfigurasi.');
        }
        Schema::dropIfExists('inventory_minimums');
    }
};
