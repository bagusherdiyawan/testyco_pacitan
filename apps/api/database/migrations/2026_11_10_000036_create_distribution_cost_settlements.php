<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_distribution_jobs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->ulid('order_id');
            $t->string('kind');
            $t->ulid('source_id');
            $t->ulid('policy_id')->nullable();
            $t->date('event_on');
            $t->string('status');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('actor_id');
            $t->text('reason')->nullable();
            $t->text('result')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'kind', 'source_id']);
        });
        Schema::create('inventory_distribution_values', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->ulid('job_id');
            $t->ulid('inventory_batch_id');
            $t->unsignedInteger('stock_revision');
            $t->bigInteger('before_quantity');
            $t->bigInteger('after_quantity');
            $t->bigInteger('before_value');
            $t->bigInteger('after_value');
            $t->bigInteger('value_delta');
            $t->timestamp('created_at');
            $t->unique(['job_id', 'inventory_batch_id']);
        });
        Schema::create('accounting_return_costs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('return_line_id')->unique();
            $t->ulid('order_id');
            $t->ulid('case_id');
            $t->ulid('invoice_id');
            $t->ulid('original_shipment_line_id');
            $t->ulid('buyer_organization_id')->index();
            $t->ulid('supplier_organization_id')->index();
            $t->ulid('buyer_policy_id');
            $t->ulid('supplier_policy_id');
            $t->ulid('dispatch_cost_entry_id');
            $t->bigInteger('sent_pieces');
            $t->bigInteger('buyer_value');
            $t->bigInteger('supplier_value');
            $t->ulid('inventory_batch_id')->nullable();
            $t->bigInteger('received_pieces')->default(0);
            $t->string('status')->default('in_transit');
            $t->date('received_on')->nullable();
            $t->bigInteger('credit_base')->default(0);
            $t->bigInteger('credit_tax')->default(0);
            $t->timestamps();
        });
        Schema::create('accounting_missing_costs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('order_id');
            $t->ulid('shipment_id');
            $t->ulid('owner_organization_id')->index();
            $t->ulid('counterparty_organization_id')->index();
            $t->ulid('counterparty_policy_id')->nullable();
            $t->ulid('source_entry_id')->unique();
            $t->ulid('source_line_id');
            $t->ulid('policy_id');
            $t->bigInteger('sent_pieces');
            $t->bigInteger('received_pieces');
            $t->bigInteger('missing_value');
            $t->date('event_on');
            $t->string('status')->default('awaiting_agreement');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedInteger('owner_share_basis_points')->nullable();
            $t->unsignedBigInteger('proposed_by')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->text('note')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['accounting_distribution_jobs', 'inventory_distribution_values', 'accounting_return_costs', 'accounting_missing_costs'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Histori costing distribusi tidak boleh dihapus.');
            }
        }
        foreach (['inventory_distribution_values', 'accounting_return_costs', 'accounting_missing_costs', 'accounting_distribution_jobs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
