<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_invoice_receipt_adjustments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->ulid('variance_id')->index();
            $t->ulid('cost_entry_id')->index();
            $t->ulid('shipment_line_id');
            $t->bigInteger('before_value');
            $t->bigInteger('after_value');
            $t->bigInteger('value_delta');
            $t->timestamp('created_at');
            $t->unique(['variance_id', 'cost_entry_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_invoice_receipt_adjustments')->exists()) {
            throw new RuntimeException('Bukti nilai sumber penerimaan tidak boleh dihapus.');
        }
        Schema::dropIfExists('inventory_invoice_receipt_adjustments');
    }
};
