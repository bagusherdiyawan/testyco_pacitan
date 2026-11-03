<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replacement_shipments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('case_id')->constrained('shipment_discrepancies')->restrictOnDelete();
            $t->foreignUlid('resolution_id')->constrained('shipment_discrepancy_resolutions')->restrictOnDelete();
            $t->foreignUlid('source_store_id')->constrained('stores')->restrictOnDelete();
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
        Schema::create('replacement_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('replacement_shipment_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('original_shipment_line_id')->constrained('shipment_lines')->restrictOnDelete();
            $t->foreignUlid('inventory_batch_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('local_product_id')->constrained('products')->restrictOnDelete();
            $t->string('sku');
            $t->string('name');
            $t->string('local_sku');
            $t->string('local_name');
            $t->string('condition', 20);
            $t->string('batch_code');
            $t->date('expires_on');
            $t->unsignedInteger('pack_size');
            $t->unsignedInteger('quantity_packs');
            $t->unsignedInteger('good_packs')->nullable();
            $t->unsignedInteger('damaged_packs')->nullable();
            $t->text('condition_note')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('replacement_shipments')->exists() || DB::table('replacement_lines')->exists()) {
            throw new RuntimeException('Rollback pengiriman pengganti ditolak setelah digunakan.');
        }
        Schema::dropIfExists('replacement_lines');
        Schema::dropIfExists('replacement_shipments');
    }
};
