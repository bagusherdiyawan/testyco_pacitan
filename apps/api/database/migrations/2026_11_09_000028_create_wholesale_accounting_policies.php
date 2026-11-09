<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_wholesale_policies', function (Blueprint $t) {
            $t->ulid('policy_id')->primary();
            $t->ulid('organization_id')->index();
            $t->ulid('base_policy_id');
            $t->unsignedInteger('base_policy_version');
            $t->string('tax_treatment');
            $t->string('freight_treatment');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('accounting_wholesale_policies')->exists()) {
            throw new RuntimeException('Pemetaan wholesale yang pernah diajukan tidak boleh dihapus.');
        }
        Schema::dropIfExists('accounting_wholesale_policies');
    }
};
