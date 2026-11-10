<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_invoice_revaluations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->ulid('invoice_id');
            $t->ulid('policy_id');
            $t->date('event_on');
            $t->string('status');
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('actor_id');
            $t->text('reason')->nullable();
            $t->text('note')->nullable();
            $t->bigInteger('stock_adjustment')->default(0);
            $t->bigInteger('cogs_adjustment')->default(0);
            $t->timestamps();
            $t->unique(['organization_id', 'invoice_id']);
        });
        Schema::create('inventory_invoice_variances', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('revaluation_id')->index();
            $t->ulid('organization_id')->index();
            $t->ulid('invoice_id');
            $t->ulid('shipment_line_id');
            $t->ulid('inventory_batch_id')->index();
            $t->unsignedInteger('stock_revision');
            $t->bigInteger('original_value');
            $t->bigInteger('final_value');
            $t->bigInteger('remaining_quantity');
            $t->bigInteger('before_value');
            $t->bigInteger('after_value');
            $t->bigInteger('stock_adjustment');
            $t->bigInteger('cogs_adjustment');
            $t->timestamp('created_at');
            $t->unique(['revaluation_id', 'inventory_batch_id']);
        });
        Schema::create('inventory_invoice_sale_adjustments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('variance_id')->index();
            $t->ulid('organization_id')->index();
            $t->ulid('cost_entry_id')->index();
            $t->bigInteger('cost_delta');
            $t->timestamp('created_at');
            $t->unique(['variance_id', 'cost_entry_id']);
        });
    }

    public function down(): void
    {
        foreach (['accounting_invoice_revaluations', 'inventory_invoice_variances', 'inventory_invoice_sale_adjustments'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Riwayat penyesuaian invoice tidak boleh dihapus.');
            }
        }
        foreach (['inventory_invoice_sale_adjustments', 'inventory_invoice_variances', 'accounting_invoice_revaluations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
