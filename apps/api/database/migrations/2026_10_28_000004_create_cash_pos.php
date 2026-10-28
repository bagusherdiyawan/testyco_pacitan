<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $t->string('cashier_name', 120);
            $t->string('device_name', 80);
            $t->string('status', 20);
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('opening_cash');
            $t->unsignedBigInteger('cash_sales')->default(0);
            $t->unsignedBigInteger('cash_in')->default(0);
            $t->unsignedBigInteger('cash_out')->default(0);
            $t->unsignedBigInteger('expected_cash');
            $t->unsignedBigInteger('counted_cash')->nullable();
            $t->bigInteger('difference_cash')->nullable();
            $t->text('closing_note')->nullable();
            $t->timestamp('opened_at');
            $t->timestamp('closed_at')->nullable();
            $t->index(['store_id', 'opened_at']);
        });
        Schema::create('active_shift_locks', function (Blueprint $t) {
            $t->foreignUlid('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $t->foreignUlid('cash_shift_id')->unique()->constrained()->restrictOnDelete();
            $t->unique(['store_id', 'cashier_id']);
        });
        Schema::create('cash_movements', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('cash_shift_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('kind', 10);
            $t->unsignedBigInteger('amount');
            $t->string('category', 80);
            $t->text('reason');
            $t->timestamp('created_at');
        });
        Schema::create('sale_intents', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('store_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('cash_shift_id')->constrained()->restrictOnDelete();
            $t->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $t->string('status', 20);
            $t->unsignedBigInteger('total_amount');
            $t->string('price_hash', 64);
            $t->json('items');
            $t->text('cancel_reason')->nullable();
            $t->timestamps();
            $t->index(['cash_shift_id', 'status']);
        });
        Schema::create('sales', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('store_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('cash_shift_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('sale_intent_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $t->string('number', 50)->unique();
            $t->string('store_name', 120);
            $t->text('store_address')->nullable();
            $t->string('cashier_name', 120);
            $t->string('status', 20);
            $t->string('currency', 3);
            $t->string('payment_method', 20);
            $t->unsignedBigInteger('subtotal');
            $t->unsignedBigInteger('discount_amount')->default(0);
            $t->unsignedBigInteger('total_amount');
            $t->unsignedBigInteger('cash_received');
            $t->unsignedBigInteger('change_amount');
            $t->timestamp('sold_at');
            $t->index(['store_id', 'sold_at']);
        });
        Schema::create('sale_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('sale_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $t->string('sku', 60);
            $t->string('name', 120);
            $t->string('condition', 20);
            $t->string('unit', 20);
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('pieces_per_unit');
            $t->unsignedBigInteger('unit_price');
            $t->unsignedBigInteger('line_total');
        });
        Schema::create('sale_allocations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('sale_line_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('inventory_batch_id')->constrained()->restrictOnDelete();
            $t->string('bucket', 20);
            $t->unsignedBigInteger('pieces');
            $t->string('batch_code', 80);
            $t->date('expires_on');
            $t->unsignedInteger('batch_pack_size');
        });
    }

    public function down(): void
    {
        if (DB::table('cash_shifts')->exists() || DB::table('sale_intents')->exists()) {
            throw new RuntimeException('Rollback POS ditolak setelah digunakan. Gunakan perbaikan maju atau restore yang ditinjau.');
        }
        foreach (['sale_allocations', 'sale_lines', 'sales', 'sale_intents', 'cash_movements', 'active_shift_locks', 'cash_shifts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
