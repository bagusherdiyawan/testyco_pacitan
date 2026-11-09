<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_policies', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->unsignedInteger('version')->default(1);
            $t->string('status');
            $t->json('mapping');
            $t->text('note');
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->timestamps();
        });
        Schema::create('inventory_values', function (Blueprint $t) {
            $t->ulid('inventory_batch_id')->primary();
            $t->ulid('organization_id')->index();
            $t->bigInteger('quantity');
            $t->bigInteger('remaining_value')->nullable();
            $t->timestamps();
        });
        Schema::create('inventory_cost_entries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->ulid('inventory_batch_id')->index();
            $t->unsignedInteger('stock_revision');
            $t->string('source_type');
            $t->string('source_id')->index();
            $t->string('origin_id')->nullable()->index();
            $t->bigInteger('quantity_delta');
            $t->bigInteger('value_delta')->nullable();
            $t->timestamp('created_at');
            $t->unique(['inventory_batch_id', 'stock_revision']);
        });
        Schema::create('accounting_value_proposals', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->ulid('inventory_batch_id');
            $t->unsignedInteger('stock_revision');
            $t->bigInteger('quantity');
            $t->bigInteger('amount');
            $t->unsignedInteger('version')->default(1);
            $t->string('status');
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->text('note');
            $t->timestamps();
        });
        Schema::create('accounting_events', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->ulid('policy_id');
            $t->string('source_type');
            $t->string('source_id');
            $t->date('event_on');
            $t->json('terms');
            $t->text('evidence');
            $t->string('status');
            $t->text('reason')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('actor_id');
            $t->ulid('journal_id')->nullable()->unique();
            $t->timestamps();
            $t->unique(['organization_id', 'source_type', 'source_id']);
            $t->index(['organization_id', 'status', 'event_on']);
        });
    }

    public function down(): void
    {
        foreach (['accounting_events', 'accounting_value_proposals', 'inventory_cost_entries', 'accounting_policies'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Riwayat akuntansi/nilai persediaan tidak boleh dihapus.');
            }
        }
        foreach (['accounting_events', 'accounting_value_proposals', 'inventory_cost_entries', 'inventory_values', 'accounting_policies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
