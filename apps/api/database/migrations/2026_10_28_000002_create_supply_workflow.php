<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_receipts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->uuid('operation_id');
            $t->string('scope', 120);
            $t->string('payload_hash', 64);
            $t->json('result')->nullable();
            $t->timestamp('created_at');
            $t->unique(['organization_id', 'operation_id']);
        });
        Schema::create('supply_orders', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('supplier_organization_id')->constrained('organizations')->restrictOnDelete();
            $t->foreignUlid('store_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('source_store_id')->nullable()->constrained('stores')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('destination_name', 120);
            $t->text('destination_address')->nullable();
            $t->string('kind', 30);
            $t->string('status', 30);
            $t->unsignedInteger('version')->default(1);
            $t->string('delivery_mode', 20);
            $t->text('note')->nullable();
            $t->text('decision_note')->nullable();
            $t->unsignedBigInteger('total_amount');
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'status']);
            $t->index(['supplier_organization_id', 'status']);
        });
        Schema::create('supply_order_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('supply_order_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('supplier_product_id')->constrained('products')->restrictOnDelete();
            $t->foreignUlid('local_product_id')->constrained('products')->restrictOnDelete();
            $t->string('sku', 60);
            $t->string('name', 120);
            $t->string('local_sku', 60);
            $t->string('local_name', 120);
            $t->string('condition', 20);
            $t->unsignedInteger('pack_size');
            $t->unsignedInteger('quantity_packs');
            $t->unsignedBigInteger('unit_price');
            $t->unique(['supply_order_id', 'supplier_product_id']);
        });
        Schema::create('inventory_batches', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('store_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $t->string('sku', 60);
            $t->string('name', 120);
            $t->string('batch_code', 80);
            $t->date('expires_on');
            $t->string('condition', 20);
            $t->unsignedInteger('pack_size');
            $t->bigInteger('usable_pieces')->default(0);
            $t->bigInteger('quarantined_pieces')->default(0);
            $t->timestamps();
            $t->unique(['store_id', 'product_id', 'batch_code', 'pack_size'], 'inventory_batch_identity');
        });
        Schema::create('stock_entries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('store_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('inventory_batch_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('bucket', 20);
            $t->bigInteger('quantity_pieces');
            $t->string('source_type', 40);
            $t->string('source_id', 80);
            $t->text('note')->nullable();
            $t->timestamp('created_at');
            $t->unique(['source_type', 'source_id', 'bucket']);
            $t->index(['store_id', 'created_at']);
        });
        Schema::create('shipments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('supply_order_id')->constrained()->restrictOnDelete();
            $t->foreignId('dispatched_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('handler', 120);
            $t->text('note')->nullable();
            $t->timestamp('dispatched_at');
            $t->timestamp('received_at')->nullable();
            $t->text('receipt_note')->nullable();
        });
        Schema::create('shipment_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('shipment_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('supply_order_line_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('inventory_batch_id')->constrained()->restrictOnDelete();
            $t->string('batch_code', 80);
            $t->date('expires_on');
            $t->unsignedInteger('pack_size');
            $t->unsignedInteger('quantity_packs');
            $t->unsignedInteger('good_packs')->nullable();
            $t->unsignedInteger('damaged_packs')->nullable();
            $t->text('condition_note')->nullable();
            $t->unique(['shipment_id', 'supply_order_line_id', 'inventory_batch_id'], 'shipment_line_allocation');
        });
    }

    public function down(): void
    {
        foreach (['shipment_lines', 'shipments', 'stock_entries', 'inventory_batches', 'supply_order_lines', 'supply_orders', 'operation_receipts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
