<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_discrepancies', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('shipment_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignUlid('supply_order_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('supplier_organization_id')->constrained('organizations')->restrictOnDelete();
            $t->foreignUlid('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 32)->default('reported');
            $t->text('reason');
            $t->string('reference', 500)->nullable();
            $t->json('snapshot');
            $t->timestamps();
        });
        Schema::create('shipment_discrepancy_resolutions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('shipment_discrepancy_id')->constrained('shipment_discrepancies', 'id', 'shipment_resolution_case_fk')->restrictOnDelete();
            $t->foreignUlid('pending_case_id')->nullable()->unique()->constrained('shipment_discrepancies')->restrictOnDelete();
            $t->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('type', 20);
            $t->string('status', 20)->default('pending');
            $t->text('proposal');
            $t->string('reference', 500)->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['shipment_discrepancy_id', 'id'], 'shipment_resolution_history');
        });
        Schema::create('shipment_discrepancy_notes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('shipment_discrepancy_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->string('side', 20);
            $t->unsignedInteger('case_version');
            $t->text('message');
            $t->string('reference', 500)->nullable();
            $t->timestamp('created_at');
            $t->unique(['shipment_discrepancy_id', 'case_version'], 'shipment_discrepancy_note_version');
        });
    }

    public function down(): void
    {
        if (DB::table('shipment_discrepancies')->exists() || DB::table('shipment_discrepancy_notes')->exists() || DB::table('shipment_discrepancy_resolutions')->exists()) {
            throw new RuntimeException('Rollback kasus selisih ditolak setelah digunakan; gunakan perbaikan maju atau restore yang ditinjau.');
        }
        Schema::dropIfExists('shipment_discrepancy_notes');
        Schema::dropIfExists('shipment_discrepancy_resolutions');
        Schema::dropIfExists('shipment_discrepancies');
    }
};
