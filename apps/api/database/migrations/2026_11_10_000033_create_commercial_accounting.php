<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_commercial_policies', function (Blueprint $t) {
            $t->ulid('policy_id')->primary();
            $t->ulid('organization_id')->index();
            $t->ulid('base_policy_id');
            $t->unsignedInteger('base_policy_version');
            $t->string('recognition_basis');
            $t->string('tax_treatment');
            $t->timestamp('created_at');
        });
        Schema::create('accounting_commercial_positions', function (Blueprint $t) {
            $t->ulid('organization_id');
            $t->ulid('commercial_position_id');
            $t->ulid('commercial_agreement_id')->index();
            $t->ulid('policy_id');
            $t->timestamp('created_at');
            $t->primary(['organization_id', 'commercial_position_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('accounting_commercial_policies')->exists() || DB::table('accounting_commercial_positions')->exists()) {
            throw new RuntimeException('Riwayat konfigurasi dan jurnal komersial tidak boleh dihapus.');
        }
        Schema::dropIfExists('accounting_commercial_positions');
        Schema::dropIfExists('accounting_commercial_policies');
    }
};
