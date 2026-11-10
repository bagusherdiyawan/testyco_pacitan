<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['accounting_invoice_revaluations', 'inventory_invoice_variances'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->bigInteger('loss_adjustment')->default(0));
        }
        Schema::create('inventory_invoice_movement_adjustments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('variance_id')->index();
            $t->ulid('organization_id')->index();
            $t->ulid('cost_entry_id')->index();
            $t->string('expense_role');
            $t->date('event_on');
            $t->bigInteger('cost_delta');
            $t->timestamp('created_at');
            $t->unique(['variance_id', 'cost_entry_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_invoice_movement_adjustments')->exists() || DB::table('inventory_invoice_variances')->where('loss_adjustment', '!=', 0)->exists()) {
            throw new RuntimeException('Riwayat koreksi pergerakan tidak boleh dihapus.');
        }
        Schema::dropIfExists('inventory_invoice_movement_adjustments');
        foreach (['accounting_invoice_revaluations', 'inventory_invoice_variances'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('loss_adjustment'));
        }
    }
};
