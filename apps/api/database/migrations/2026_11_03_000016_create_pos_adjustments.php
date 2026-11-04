<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_shifts', function (Blueprint $t) {
            $t->unsignedBigInteger('noncash_sales')->default(0);
            $t->unsignedBigInteger('noncash_refunds')->default(0);
        });
        Schema::table('sale_intents', function (Blueprint $t) {
            $t->unsignedBigInteger('subtotal')->nullable();
            $t->unsignedBigInteger('discount_amount')->default(0);
            $t->json('voucher_snapshot')->nullable();
        });
        Schema::table('sales', function (Blueprint $t) {
            $t->unsignedInteger('version')->default(1);
            $t->string('payment_reference', 160)->nullable();
            $t->foreignId('payment_verified_by')->nullable()->constrained('users');
            $t->timestamp('payment_verified_at')->nullable();
            $t->json('voucher_snapshot')->nullable();
            $t->unique(['organization_id', 'payment_method', 'payment_reference'], 'sale_payment_reference');
        });
        Schema::table('sale_lines', function (Blueprint $t) {
            $t->unsignedBigInteger('discount_amount')->default(0);
        });
        Schema::create('vouchers', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('funding_organization_id')->constrained('organizations');
            $t->string('code', 60);
            $t->unsignedInteger('version');
            $t->boolean('active');
            $t->string('kind');
            $t->unsignedBigInteger('value');
            $t->unsignedBigInteger('maximum_discount');
            $t->unsignedBigInteger('minimum_subtotal');
            $t->unsignedInteger('usage_limit');
            $t->unsignedInteger('used_count')->default(0);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->json('store_ids');
            $t->json('product_ids');
            $t->string('restore_policy');
            $t->text('terms');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
        });
        Schema::create('voucher_redemptions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('voucher_id')->constrained();
            $t->foreignUlid('sale_id')->unique()->constrained();
            $t->unsignedBigInteger('discount_amount');
            $t->timestamp('redeemed_at');
            $t->timestamp('restored_at')->nullable();
        });
        Schema::create('sale_refunds', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('sale_id')->constrained();
            $t->foreignUlid('cash_shift_id')->constrained();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('payment_method');
            $t->string('reference', 160);
            $t->string('stock_disposition');
            $t->unsignedBigInteger('amount');
            $t->json('lines');
            $t->text('reason');
            $t->timestamp('refunded_at');
            $t->unique(['sale_id', 'reference']);
        });
    }

    public function down(): void
    {
        if (DB::table('sale_refunds')->exists() || DB::table('vouchers')->exists() || DB::table('sales')->where('payment_method', '!=', 'cash')->exists()) {
            throw new RuntimeException('POS financial history requires forward migration.');
        }Schema::dropIfExists('sale_refunds');
        Schema::dropIfExists('voucher_redemptions');
        Schema::dropIfExists('vouchers');
        Schema::table('sale_lines', fn (Blueprint $t) => $t->dropColumn('discount_amount'));
        Schema::table('sales', function (Blueprint $t) {
            $t->dropUnique('sale_payment_reference');
            $t->dropForeign(['payment_verified_by']);
            $t->dropColumn(['version', 'payment_reference', 'payment_verified_by', 'payment_verified_at', 'voucher_snapshot']);
        });
        Schema::table('sale_intents', fn (Blueprint $t) => $t->dropColumn(['subtotal', 'discount_amount', 'voucher_snapshot']));
        Schema::table('cash_shifts', fn (Blueprint $t) => $t->dropColumn(['noncash_sales', 'noncash_refunds']));
    }
};
