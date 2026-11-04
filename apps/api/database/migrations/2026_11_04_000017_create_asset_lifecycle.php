<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_assets', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->string('number', 80);
            $t->string('kind');
            $t->string('brand', 120);
            $t->string('serial_number', 160);
            $t->string('status');
            $t->unsignedInteger('version');
            $t->text('condition_note');
            $t->text('ownership_reference');
            $t->foreignUlid('store_id')->nullable()->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['organization_id', 'number']);
        });
        Schema::create('asset_placements', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('business_asset_id')->constrained();
            $t->ulid('active_asset_id')->nullable()->unique();
            $t->foreignUlid('owner_organization_id')->constrained('organizations');
            $t->foreignUlid('recipient_organization_id')->constrained('organizations');
            $t->foreignUlid('store_id')->constrained();
            $t->foreignUlid('contract_id')->nullable()->constrained('partnership_contracts');
            $t->string('status');
            $t->unsignedInteger('version');
            $t->string('responsible_name', 120);
            $t->date('handed_over_on');
            $t->text('condition_note');
            $t->text('agreement_reference');
            $t->text('evidence_reference');
            $t->foreignId('proposed_by')->constrained('users');
            $t->foreignId('confirmed_by')->nullable()->constrained('users');
            $t->timestamp('confirmed_at')->nullable();
            $t->date('returned_on')->nullable();
            $t->text('return_condition')->nullable();
            $t->text('return_reference')->nullable();
            $t->foreignId('return_requested_by')->nullable()->constrained('users');
            $t->foreignId('return_confirmed_by')->nullable()->constrained('users');
            $t->timestamp('return_confirmed_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
        });
        Schema::create('asset_incidents', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('business_asset_id')->constrained();
            $t->foreignUlid('asset_placement_id')->nullable()->constrained();
            $t->foreignUlid('owner_organization_id')->constrained('organizations');
            $t->foreignUlid('recipient_organization_id')->nullable()->constrained('organizations');
            $t->foreignUlid('store_id')->nullable()->constrained();
            $t->string('status');
            $t->unsignedInteger('version');
            $t->text('description');
            $t->text('evidence_reference');
            $t->foreignId('reported_by')->constrained('users');
            $t->date('reported_on');
            $t->text('resolution')->nullable();
            $t->text('cost_basis_reference')->nullable();
            $t->unsignedBigInteger('owner_cost')->nullable();
            $t->unsignedBigInteger('recipient_cost')->nullable();
            $t->date('completed_on')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('business_assets')->exists()) {
            throw new RuntimeException('Asset history requires forward migration.');
        }foreach (['asset_incidents', 'asset_placements', 'business_assets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
