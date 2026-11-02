<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_changes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('supply_order_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('pending_order_id')->nullable()->unique()->constrained('supply_orders')->restrictOnDelete();
            $t->unsignedInteger('base_version');
            $t->unsignedInteger('pending_version');
            $t->string('status', 20);
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->text('decision_note')->nullable();
            $t->json('before');
            $t->json('after');
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['supply_order_id', 'id']);
        });
    }

    public function down(): void
    {
        if (DB::table('order_changes')->exists()) {
            throw new RuntimeException('Rollback revisi pesanan ditolak setelah digunakan. Gunakan perbaikan maju atau restore yang ditinjau.');
        }
        Schema::dropIfExists('order_changes');
    }
};
