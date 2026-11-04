<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partnership_contracts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('partner_id')->constrained('organizations')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 20)->default('draft');
            $t->string('title', 160);
            $t->string('mode', 20);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->text('terms');
            $t->text('brand_terms');
            $t->text('data_scope');
            $t->text('facilities');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('decision_note')->nullable();
            $t->string('consent_name', 160)->nullable();
            $t->string('terms_sha256', 64)->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['partner_id', 'status']);
        });
    }

    public function down(): void
    {
        if (DB::table('partnership_contracts')->exists()) {
            throw new RuntimeException('Rollback kontrak terpakai ditolak.');
        }Schema::dropIfExists('partnership_contracts');
    }
};
