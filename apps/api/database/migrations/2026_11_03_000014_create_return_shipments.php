<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_shipments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('case_id')->constrained('shipment_discrepancies')->restrictOnDelete();
            $t->foreignUlid('resolution_id')->constrained('shipment_discrepancy_resolutions')->restrictOnDelete();
            $t->foreignUlid('source_store_id')->constrained('stores')->restrictOnDelete();
            $t->foreignUlid('target_store_id')->constrained('stores')->restrictOnDelete();
            $t->foreignId('dispatched_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('handler', 120);
            $t->text('note')->nullable();
            $t->text('receipt_note')->nullable();
            $t->timestamp('dispatched_at');
            $t->timestamp('received_at')->nullable();
            $t->timestamps();
            $t->index(['case_id', 'id']);
        });
        Schema::create('return_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('return_shipment_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('original_shipment_line_id')->constrained('shipment_lines')->restrictOnDelete();
            $t->foreignUlid('inventory_batch_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('supplier_product_id')->constrained('products')->restrictOnDelete();
            $t->string('sku', 60);
            $t->string('name', 120);
            $t->string('local_sku', 60);
            $t->string('local_name', 120);
            $t->string('condition', 20);
            $t->string('batch_code');
            $t->date('expires_on');
            $t->unsignedInteger('pack_size');
            $t->unsignedInteger('quantity_packs');
            $t->unsignedInteger('received_packs')->nullable();
            $t->text('condition_note')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('return_shipments')->exists()) {
            throw new RuntimeException('Rollback retur terpakai ditolak.');
        }Schema::dropIfExists('return_lines');
        Schema::dropIfExists('return_shipments');
    }
};
