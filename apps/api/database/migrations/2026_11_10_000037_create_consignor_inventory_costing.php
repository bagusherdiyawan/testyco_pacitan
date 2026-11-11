<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consignor_valuations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->ulid('partner_id');
            $t->ulid('agreement_id')->unique();
            $t->ulid('order_id')->unique();
            $t->ulid('policy_id');
            $t->string('status');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('actor_id');
            $t->date('activated_on');
            $t->text('reason')->nullable();
            $t->timestamps();
        });
        Schema::create('consignor_lots', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('valuation_id')->index();
            $t->string('source_type');
            $t->ulid('source_id');
            $t->ulid('source_cost_entry_id')->unique();
            $t->ulid('order_line_id');
            $t->bigInteger('quantity');
            $t->bigInteger('value')->nullable();
            $t->bigInteger('received_quantity')->default(0);
            $t->bigInteger('received_value')->nullable();
            $t->bigInteger('settled_missing_quantity')->default(0);
            $t->bigInteger('settled_missing_value')->default(0);
            $t->boolean('receipt_confirmed')->default(false);
            $t->timestamps();
            $t->unique(['source_type', 'source_id']);
        });
        Schema::create('consignor_pools', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('valuation_id')->index();
            $t->ulid('organization_id')->index();
            $t->ulid('partner_id');
            $t->ulid('inventory_batch_id')->unique();
            $t->ulid('order_line_id');
            $t->bigInteger('quantity');
            $t->bigInteger('remaining_value')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('consignor_cost_entries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->ulid('pool_id')->index();
            $t->ulid('inventory_batch_id');
            $t->ulid('policy_id');
            $t->string('source_type');
            $t->string('source_id');
            $t->ulid('origin_id')->nullable();
            $t->bigInteger('cost_offset')->nullable();
            $t->bigInteger('quantity_delta');
            $t->bigInteger('value_delta')->nullable();
            $t->date('event_on')->nullable();
            $t->unsignedBigInteger('actor_id');
            $t->timestamp('created_at');
            $t->unique(['pool_id', 'source_type', 'source_id']);
        });
        Schema::create('consignor_bindings', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('pool_id')->unique();
            $t->ulid('organization_id');
            $t->ulid('inventory_batch_id');
            $t->unsignedInteger('stock_revision');
            $t->bigInteger('before_quantity');
            $t->bigInteger('before_value')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('consignor_returns', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('return_line_id')->unique();
            $t->ulid('valuation_id');
            $t->ulid('source_entry_id')->unique();
            $t->bigInteger('quantity');
            $t->bigInteger('value')->nullable();
            $t->bigInteger('received_quantity')->default(0);
            $t->bigInteger('received_value')->nullable();
            $t->bigInteger('settled_missing_value')->default(0);
            $t->bigInteger('settled_missing_quantity')->default(0);
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('consignor_dispositions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->ulid('entry_id')->unique();
            $t->ulid('event_id');
            $t->bigInteger('quantity');
            $t->bigInteger('value')->nullable();
            $t->string('status');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
        });
        Schema::table('accounting_missing_costs', function (Blueprint $t) {
            $t->ulid('review_event_id')->nullable();
            $t->ulid('consignor_entry_id')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['consignor_valuations', 'consignor_lots', 'consignor_pools', 'consignor_cost_entries', 'consignor_bindings', 'consignor_returns', 'consignor_dispositions'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Bukti ownership/HPP konsinyasi terpakai tidak boleh dihapus.');
            }
        }
        Schema::table('accounting_missing_costs', function (Blueprint $t) {
            $t->dropColumn(['review_event_id', 'consignor_entry_id']);
        });
        foreach (['consignor_dispositions', 'consignor_returns', 'consignor_bindings', 'consignor_cost_entries', 'consignor_pools', 'consignor_lots', 'consignor_valuations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
