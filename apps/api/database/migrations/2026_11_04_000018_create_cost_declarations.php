<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_declarations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->string('source_type');
            $t->ulid('source_id');
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('goods_cost');
            $t->unsignedBigInteger('operating_cost');
            $t->text('evidence_reference');
            $t->text('reason');
            $t->foreignId('recorded_by')->constrained('users');
            $t->timestamp('recorded_at');
            $t->unique(['organization_id', 'source_type', 'source_id', 'version'], 'cost_declaration_version');
        });
    }

    public function down(): void
    {
        if (DB::table('cost_declarations')->exists()) {
            throw new RuntimeException('Cost evidence history requires forward migration.');
        }Schema::dropIfExists('cost_declarations');
    }
};
