<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_operator_grants', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->boolean('enabled');
            $t->unsignedInteger('version');
            $t->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->timestamps();
            $t->unique(['organization_id', 'store_id', 'user_id']);
        });
        Schema::create('accounting_accounts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->string('code', 40);
            $t->string('name', 160);
            $t->string('kind', 20);
            $t->string('normal_balance', 10);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
        });
        Schema::create('accounting_periods', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 20);
            $t->unsignedInteger('version')->default(1);
            $t->text('note');
            $t->timestamps();
            $t->index(['organization_id', 'starts_on', 'ends_on']);
        });
        Schema::create('accounting_journals', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('period_id')->constrained('accounting_periods')->restrictOnDelete();
            $t->string('number', 80);
            $t->date('posted_on');
            $t->string('status', 20);
            $t->unsignedInteger('version')->default(1);
            $t->json('lines');
            $t->unsignedBigInteger('total_amount');
            $t->text('evidence');
            $t->text('note');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignUlid('reversal_of')->nullable()->constrained('accounting_journals')->restrictOnDelete();
            $t->foreignUlid('reversed_by')->nullable()->constrained('accounting_journals')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'number']);
            $t->unique('reversal_of');
        });
    }

    public function down(): void
    {
        foreach (['erp_operator_grants', 'accounting_accounts', 'accounting_periods', 'accounting_journals'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Riwayat izin/akuntansi digunakan tidak boleh dihapus.');
            }
        }foreach (['accounting_journals', 'accounting_periods', 'accounting_accounts', 'erp_operator_grants'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
