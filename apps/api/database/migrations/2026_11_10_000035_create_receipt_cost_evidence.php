<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_receipt_costs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->ulid('shipment_line_id');
            $t->ulid('policy_id');
            $t->unsignedBigInteger('actor_id');
            $t->date('received_on');
            $t->bigInteger('good_pieces');
            $t->bigInteger('quarantined_pieces');
            $t->bigInteger('missing_pieces');
            $t->bigInteger('quoted_value');
            $t->timestamp('created_at');
            $t->unique(['organization_id', 'shipment_line_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('accounting_receipt_costs')->exists()) {
            throw new RuntimeException('Bukti penerimaan/nilai yang dipakai tidak boleh dihapus.');
        }
        Schema::dropIfExists('accounting_receipt_costs');
    }
};
