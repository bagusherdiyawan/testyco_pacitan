<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_permits', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('store_id')->constrained();
            $t->foreignUlid('cash_shift_id')->constrained();
            $t->foreignId('cashier_id')->constrained('users');
            $t->unsignedInteger('access_version');
            $t->json('snapshot');
            $t->timestamp('issued_at');
            $t->timestamp('expires_at');
            $t->timestamp('closed_at')->nullable();
        });
        Schema::create('offline_sales', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('offline_permit_id')->constrained();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('store_id')->constrained();
            $t->foreignUlid('cash_shift_id')->constrained();
            $t->foreignId('cashier_id')->constrained('users');
            $t->uuid('operation_id');
            $t->string('status');
            $t->unsignedInteger('version');
            $t->json('payload');
            $t->json('snapshot')->nullable();
            $t->ulid('sale_id')->nullable()->unique();
            $t->string('conflict_code')->nullable();
            $t->text('conflict_message')->nullable();
            $t->timestamp('captured_at');
            $t->timestamp('received_at');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->text('reconciliation_reference')->nullable();
            $t->unique(['organization_id', 'operation_id']);
        });
        Schema::table('sales', function (Blueprint $t) {
            $t->foreignUlid('offline_sale_id')->nullable()->unique()->constrained('offline_sales');
            $t->timestamp('synced_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('offline_permits')->exists() || DB::table('offline_sales')->exists()) {
            throw new RuntimeException('Offline cash evidence requires forward migration.');
        }Schema::table('sales', function (Blueprint $t) {
            $t->dropForeign(['offline_sale_id']);
            $t->dropUnique(['offline_sale_id']);
            $t->dropColumn(['offline_sale_id', 'synced_at']);
        });
        Schema::dropIfExists('offline_sales');
        Schema::dropIfExists('offline_permits');
    }
};
