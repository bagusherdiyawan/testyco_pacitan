<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Non-food supplies (packaging, cutlery) tracked by simple per-store quantity, no batches.
        Schema::create('consumable_items', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->string('name', 120);
            $t->string('unit', 30);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['organization_id', 'name']);
        });
        Schema::create('consumable_rules', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->string('service_type', 20);
            $t->ulid('consumable_item_id');
            $t->string('basis', 20);
            $t->unsignedInteger('quantity');
            $t->timestamps();
            $t->unique(['organization_id', 'service_type', 'consumable_item_id']);
        });
        Schema::create('consumable_stock_entries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->ulid('store_id');
            $t->ulid('consumable_item_id');
            $t->integer('delta');
            $t->string('reason_type', 30);
            $t->string('source_type', 40);
            $t->string('source_id', 40);
            $t->unsignedBigInteger('amount')->nullable();
            $t->unsignedBigInteger('actor_id');
            $t->string('note', 500)->nullable();
            $t->timestamp('created_at');
            $t->index(['store_id', 'consumable_item_id']);
            $t->unique(['source_type', 'source_id', 'consumable_item_id']);
        });
        Schema::table('sale_intents', function (Blueprint $t) {
            $t->string('service_type', 20)->nullable();
        });
        Schema::table('sales', function (Blueprint $t) {
            $t->string('service_type', 20)->nullable();
            $t->json('consumption')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('consumable_stock_entries')->exists()) {
            throw new RuntimeException('Riwayat stok barang non-makanan tidak boleh dihapus.');
        }
        Schema::table('sales', function (Blueprint $t) {
            $t->dropColumn(['service_type', 'consumption']);
        });
        Schema::table('sale_intents', function (Blueprint $t) {
            $t->dropColumn('service_type');
        });
        Schema::dropIfExists('consumable_stock_entries');
        Schema::dropIfExists('consumable_rules');
        Schema::dropIfExists('consumable_items');
    }
};
