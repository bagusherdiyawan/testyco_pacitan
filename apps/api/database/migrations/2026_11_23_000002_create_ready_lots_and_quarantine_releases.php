<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_ready_lots', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->ulid('store_id')->index();
            $t->ulid('inventory_batch_id')->index();
            $t->ulid('inventory_process_id')->nullable();
            $t->timestamp('cooked_at');
            $t->timestamp('ready_expires_at')->nullable();
            $t->unsignedInteger('initial_pieces');
            $t->unsignedInteger('remaining_pieces');
            $t->timestamps();
            $t->index(['inventory_batch_id', 'remaining_pieces', 'ready_expires_at'], 'ready_lots_fefo_idx');
        });

        Schema::create('quarantine_releases', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->ulid('store_id')->index();
            $t->ulid('inventory_batch_id')->index();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->string('status', 20)->default('pending');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedInteger('snapshot_revision');
            $t->string('target_bucket', 20);
            $t->unsignedInteger('quantity_pieces');
            $t->text('reason');
            $t->text('review_note')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_ready_lots')->exists() || DB::table('quarantine_releases')->exists()) {
            throw new RuntimeException('Riwayat lot siap jual dan pelepasan karantina tidak boleh dihapus.');
        }
        Schema::dropIfExists('quarantine_releases');
        Schema::dropIfExists('inventory_ready_lots');
    }
};
