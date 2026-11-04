<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_agreements', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('contract_id')->constrained('partnership_contracts');
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('partner_id')->constrained('organizations');
            $t->foreignUlid('proposer_organization_id')->constrained('organizations');
            $t->foreignUlid('supply_order_id')->nullable()->constrained();
            $t->ulid('active_order_id')->nullable()->unique();
            $t->string('kind');
            $t->string('status');
            $t->unsignedInteger('version');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->json('terms');
            $t->text('reference');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('stopped_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->unsignedBigInteger('budget_spent')->default(0);
            $t->string('last_error_code')->nullable();
            $t->timestamp('last_processed_at')->nullable();
            $t->timestamps();
        });
        Schema::table('vouchers', function (Blueprint $t) {
            $t->foreignUlid('commercial_agreement_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        Schema::create('commercial_positions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('commercial_agreement_id')->constrained();
            $t->foreignUlid('sale_line_id')->nullable()->constrained();
            $t->foreignUlid('sale_id')->constrained();
            $t->string('source_key', 100);
            $t->unique(['commercial_agreement_id', 'source_key']);
            $t->unsignedInteger('sale_version');
            $t->unsignedInteger('version');
            $t->bigInteger('basis_amount');
            $t->bigInteger('amount');
            $t->unsignedBigInteger('refunded_quantity')->default(0);
            $t->timestamp('updated_at');
            $t->unique(['commercial_agreement_id', 'sale_line_id']);
            $t->unique(['commercial_agreement_id', 'sale_id', 'sale_line_id']);
        });
        Schema::create('commercial_entries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('commercial_agreement_id')->constrained();
            $t->foreignUlid('commercial_position_id')->constrained();
            $t->unsignedInteger('position_version');
            $t->bigInteger('amount');
            $t->bigInteger('basis_amount');
            $t->json('snapshot');
            $t->timestamp('created_at');
            $t->unique(['commercial_position_id', 'position_version']);
        });
        Schema::create('commercial_payments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('commercial_agreement_id')->constrained();
            $t->bigInteger('signed_amount');
            $t->string('method');
            $t->string('reference', 160);
            $t->text('evidence_reference');
            $t->foreignId('verified_by')->constrained('users');
            $t->date('paid_on');
            $t->timestamp('created_at');
            $t->unique(['commercial_agreement_id', 'reference']);
        });
    }

    public function down(): void
    {
        if (DB::table('commercial_agreements')->exists()) {
            throw new RuntimeException('Commercial automation history requires forward migration.');
        }Schema::dropIfExists('commercial_payments');
        Schema::dropIfExists('commercial_entries');
        Schema::dropIfExists('commercial_positions');
        Schema::table('vouchers', function (Blueprint $t) {
            $t->dropForeign(['commercial_agreement_id']);
            $t->dropColumn('commercial_agreement_id');
        });
        Schema::dropIfExists('commercial_agreements');
    }
};
