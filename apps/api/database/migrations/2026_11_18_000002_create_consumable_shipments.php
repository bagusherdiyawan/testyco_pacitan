<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Center-to-booth supply shipments. Quantity only: no price, invoice or accounting.
        Schema::create('consumable_shipments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->ulid('source_store_id')->index();
            $t->ulid('destination_organization_id')->index();
            $t->ulid('destination_store_id')->index();
            $t->string('status', 20);
            $t->string('note', 500)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('dispatched_by');
            $t->timestamp('dispatched_at');
            $t->unsignedBigInteger('received_by')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->string('receive_note', 1000)->nullable();
        });
        Schema::create('consumable_shipment_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('shipment_id')->index();
            $t->ulid('source_item_id');
            $t->string('name', 120);
            $t->string('unit', 30);
            $t->unsignedInteger('quantity_sent');
            $t->ulid('destination_item_id')->nullable();
            $t->unsignedInteger('quantity_received')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('consumable_shipments')->exists()) {
            throw new RuntimeException('Riwayat kiriman barang non-makanan tidak boleh dihapus.');
        }
        Schema::dropIfExists('consumable_shipment_lines');
        Schema::dropIfExists('consumable_shipments');
    }
};
