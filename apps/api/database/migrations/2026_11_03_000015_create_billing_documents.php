<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consignment_reports', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('supply_order_id')->constrained();
            $t->foreignUlid('contract_id')->constrained('partnership_contracts');
            $t->string('status');
            $t->unsignedInteger('version');
            $t->json('lines');
            $t->string('period_start', 10);
            $t->string('period_end', 10);
            $t->text('note');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('supply_order_id')->constrained();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('partner_id')->constrained('organizations');
            $t->foreignUlid('consignment_report_id')->nullable()->constrained();
            $t->ulid('active_report_id')->nullable()->unique();
            $t->string('number')->unique();
            $t->string('status');
            $t->unsignedInteger('version');
            $t->string('basis');
            $t->string('mode');
            $t->string('currency', 3);
            $t->string('rounding');
            $t->date('due_on');
            $t->unsignedBigInteger('subtotal');
            $t->unsignedBigInteger('discount_amount');
            $t->unsignedBigInteger('shipping_amount');
            $t->unsignedInteger('tax_basis_points');
            $t->unsignedBigInteger('tax_amount');
            $t->unsignedBigInteger('total_amount');
            $t->json('lines');
            $t->text('note');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
        });
        Schema::create('invoice_shipment_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('invoice_id')->constrained();
            $t->foreignUlid('shipment_line_id')->constrained();
            $t->ulid('active_shipment_line_id')->nullable()->unique();
        });
        Schema::create('invoice_payments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('invoice_id')->constrained();
            $t->string('kind');
            $t->string('method');
            $t->unsignedBigInteger('amount');
            $t->string('reference', 160);
            $t->date('paid_on');
            $t->text('note');
            $t->string('status');
            $t->foreignId('verified_by')->constrained('users');
            $t->timestamp('verified_at');
            $t->foreignId('reversed_by')->nullable()->constrained('users');
            $t->timestamp('reversed_at')->nullable();
            $t->text('reversal_reason')->nullable();
            $t->unique(['invoice_id', 'reference']);
        });
        Schema::create('invoice_credits', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('invoice_id')->constrained();
            $t->foreignUlid('case_id')->constrained('shipment_discrepancies');
            $t->foreignUlid('resolution_id')->constrained('shipment_discrepancy_resolutions');
            $t->ulid('pending_invoice_id')->nullable()->unique();
            $t->string('status');
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('base_amount');
            $t->unsignedBigInteger('tax_amount');
            $t->text('reason');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['invoices', 'consignment_reports'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Billing history requires forward migration.');
            }
        }foreach (['invoice_credits', 'invoice_payments', 'invoice_shipment_lines', 'invoices', 'consignment_reports'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
