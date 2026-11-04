<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recall_campaigns', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('batch_recall_id')->unique()->constrained('batch_recalls')->restrictOnDelete();
            $t->foreignUlid('organization_id')->constrained();
            $t->string('status');
            $t->unsignedInteger('version');
            $t->text('instructions');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamp('created_at');
            $t->timestamp('closed_at')->nullable();
        });
        Schema::create('recall_targets', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('recall_campaign_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('inventory_batch_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('parent_batch_id')->nullable()->constrained('inventory_batches');
            $t->string('source_type')->nullable();
            $t->string('source_id')->nullable();
            $t->string('status');
            $t->unsignedInteger('version');
            $t->text('evidence_reference')->nullable();
            $t->text('reason')->nullable();
            $t->unsignedBigInteger('observed_pieces')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('created_at');
            $t->unique(['recall_campaign_id', 'inventory_batch_id']);
            $t->index(['inventory_batch_id', 'status']);
        });
    }

    public function down(): void
    {
        if (DB::table('recall_campaigns')->exists()) {
            throw new RuntimeException('Coordinated recall history requires forward migration.');
        }Schema::dropIfExists('recall_targets');
        Schema::dropIfExists('recall_campaigns');
    }
};
