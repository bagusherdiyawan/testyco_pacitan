<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_batches', function (Blueprint $t) {
            $t->bigInteger('opened_pieces')->default(0);
            $t->bigInteger('ready_pieces')->default(0);
            $t->bigInteger('waste_pieces')->default(0);
            $t->unsignedBigInteger('stock_revision')->default(0);
        });
        Schema::create('inventory_processes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('store_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('inventory_batch_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('kind', 20);
            $t->unsignedInteger('pack_size');
            $t->unsignedBigInteger('input_pieces');
            $t->unsignedBigInteger('output_pieces');
            $t->unsignedBigInteger('waste_pieces');
            $t->text('reason');
            $t->json('before');
            $t->json('after');
            $t->timestamp('created_at');
            $t->index(['store_id', 'created_at']);
        });
        Schema::create('inventory_counts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('store_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('inventory_batch_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('purpose', 20);
            $t->string('bucket', 20);
            $t->string('status', 20)->default('pending');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('snapshot_revision');
            $t->unsignedBigInteger('expected_pieces');
            $t->unsignedBigInteger('counted_pieces');
            $t->bigInteger('difference_pieces');
            $t->text('reason');
            $t->text('review_note')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_processes')->exists() || DB::table('inventory_counts')->exists() || DB::table('inventory_batches')->where('opened_pieces', '!=', 0)->orWhere('ready_pieces', '!=', 0)->orWhere('waste_pieces', '!=', 0)->exists()) {
            throw new RuntimeException('Rollback ditolak: kontrol stok sudah digunakan. Gunakan migrasi perbaikan maju atau prosedur restore yang ditinjau.');
        }
        Schema::dropIfExists('inventory_counts');
        Schema::dropIfExists('inventory_processes');
        Schema::table('inventory_batches', fn (Blueprint $t) => $t->dropColumn(['opened_pieces', 'ready_pieces', 'waste_pieces', 'stock_revision']));
    }
};
