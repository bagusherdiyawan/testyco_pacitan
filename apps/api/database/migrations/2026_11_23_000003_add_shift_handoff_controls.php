<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $t) {
            $t->boolean('single_active_shift')->default(true);
        });

        Schema::table('cash_shifts', function (Blueprint $t) {
            $t->ulid('previous_shift_id')->nullable()->index();
            $t->unsignedBigInteger('expected_opening_cash')->nullable();
            $t->bigInteger('opening_discrepancy')->nullable();
            $t->text('opening_note')->nullable();
            $t->unsignedBigInteger('retained_cash')->nullable();
            $t->unsignedBigInteger('deposit_cash')->nullable();
            $t->foreignId('force_closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('force_close_reason')->nullable();
        });

        Schema::create('cash_movement_requests', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('store_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('cash_shift_id')->constrained()->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->string('kind', 10);
            $t->unsignedBigInteger('amount');
            $t->string('category', 80);
            $t->text('reason');
            $t->string('evidence_reference', 160)->nullable();
            $t->string('status', 20);
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('decision_note')->nullable();
            $t->foreignUlid('cash_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('created_at');
            $t->timestamp('decided_at')->nullable();
            $t->index(['cash_shift_id', 'status']);
            $t->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        if (
            DB::table('cash_movement_requests')->exists()
            || DB::table('cash_shifts')->whereNotNull('previous_shift_id')->exists()
            || DB::table('cash_shifts')->whereNotNull('retained_cash')->exists()
            || DB::table('cash_shifts')->whereNotNull('force_closed_by')->exists()
        ) {
            throw new \RuntimeException('Rollback kontrol serah-terima shift yang sudah dipakai ditolak.');
        }

        Schema::dropIfExists('cash_movement_requests');

        Schema::table('cash_shifts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('force_closed_by');
            $t->dropColumn([
                'previous_shift_id',
                'expected_opening_cash',
                'opening_discrepancy',
                'opening_note',
                'retained_cash',
                'deposit_cash',
                'force_close_reason',
            ]);
        });

        Schema::table('stores', function (Blueprint $t) {
            $t->dropColumn('single_active_shift');
        });
    }
};
