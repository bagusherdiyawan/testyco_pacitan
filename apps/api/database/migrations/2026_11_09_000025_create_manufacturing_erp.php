<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_materials', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->string('code', 60);
            $t->string('name', 120);
            $t->string('unit', 10);
            $t->boolean('expiry_required');
            $t->boolean('fefo_required');
            $t->unsignedBigInteger('minimum_quantity');
            $t->unsignedInteger('version')->default(1);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
        });
        Schema::create('erp_suppliers', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->text('contact');
            $t->text('address');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('erp_purchases', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('store_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('supplier_id')->constrained('erp_suppliers')->restrictOnDelete();
            $t->string('number', 80);
            $t->string('status', 30);
            $t->unsignedInteger('version')->default(1);
            $t->date('ordered_on');
            $t->date('due_on');
            $t->json('lines');
            $t->unsignedBigInteger('total_amount');
            $t->unsignedBigInteger('received_amount')->default(0);
            $t->unsignedBigInteger('credited_amount')->default(0);
            $t->unsignedBigInteger('paid_amount')->default(0);
            $t->unsignedBigInteger('refunded_amount')->default(0);
            $t->json('payments');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->text('note');
            $t->timestamps();
            $t->unique(['organization_id', 'number']);
        });
        Schema::create('erp_material_lots', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('store_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('material_id')->constrained('erp_materials')->restrictOnDelete();
            $t->foreignUlid('purchase_id')->nullable()->constrained('erp_purchases')->restrictOnDelete();
            $t->string('material_name');
            $t->string('unit', 10);
            $t->string('batch_code', 80);
            $t->date('received_on');
            $t->date('expires_on')->nullable();
            $t->unsignedBigInteger('usable_quantity')->default(0);
            $t->unsignedBigInteger('quarantined_quantity')->default(0);
            $t->unsignedBigInteger('waste_quantity')->default(0);
            $t->unsignedBigInteger('remaining_value')->default(0);
            $t->unsignedBigInteger('stock_revision')->default(0);
            $t->boolean('blocked')->default(false);
            $t->json('provenance');
            $t->text('evidence');
            $t->timestamps();
            $t->index(['organization_id', 'store_id', 'material_id']);
        });
        Schema::create('erp_material_entries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('material_lot_id')->constrained('erp_material_lots')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('bucket', 30);
            $t->bigInteger('quantity_delta');
            $t->bigInteger('value_delta');
            $t->index(['organization_id', 'material_lot_id', 'source_type']);
            $t->string('source_type', 40);
            $t->ulid('source_id');
            $t->text('note');
            $t->timestamp('created_at');
        });
        Schema::create('erp_recipes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('product_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUlid('output_material_id')->nullable()->constrained('erp_materials')->restrictOnDelete();
            $t->string('output_kind', 20);
            $t->unsignedBigInteger('yield_quantity')->nullable();
            $t->string('yield_unit', 10)->nullable();
            $t->json('material_snapshot')->nullable();
            $t->string('title', 160);
            $t->unsignedInteger('version')->default(1);
            $t->unsignedInteger('output_packs')->nullable();
            $t->json('ingredients');
            $t->json('product_snapshot')->nullable();
            $t->text('instructions');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('erp_productions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('store_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('recipe_id')->constrained('erp_recipes')->restrictOnDelete();
            $t->string('number', 80);
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 30);
            $t->unsignedInteger('runs');
            $t->json('recipe_snapshot');
            $t->json('allocations');
            $t->json('costs');
            $t->string('batch_code', 80)->nullable();
            $t->date('produced_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->unsignedBigInteger('good_quantity')->nullable();
            $t->unsignedBigInteger('failed_quantity')->nullable();
            $t->foreignUlid('output_material_lot_id')->nullable()->constrained('erp_material_lots')->restrictOnDelete();
            $t->unsignedInteger('good_packs')->nullable();
            $t->unsignedInteger('failed_pieces')->nullable();
            $t->foreignUlid('output_batch_id')->nullable()->constrained('inventory_batches')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('inspected_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('note');
            $t->text('inspection_note')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'number']);
        });
        Schema::create('erp_material_counts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('material_lot_id')->constrained('erp_material_lots')->restrictOnDelete();
            $t->string('bucket', 30);
            $t->string('status', 30);
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('stock_revision');
            $t->unsignedBigInteger('counted_quantity');
            $t->unsignedBigInteger('adjustment_value');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['erp_materials', 'erp_suppliers', 'erp_purchases', 'erp_material_lots', 'erp_recipes', 'erp_productions', 'erp_material_counts'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Riwayat ERP yang digunakan tidak boleh dihapus.');
            }
        }
        foreach (['erp_material_counts', 'erp_productions', 'erp_recipes', 'erp_material_entries', 'erp_material_lots', 'erp_purchases', 'erp_suppliers', 'erp_materials'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
