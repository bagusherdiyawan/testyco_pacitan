<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_sops', function (Blueprint $t) {
            $t->foreignUlid('organization_id')->primary()->constrained();
            $t->unsignedInteger('version');
            $t->boolean('enabled');
            $t->boolean('block_expired');
            $t->string('fefo');
            $t->unsignedInteger('ready_life_minutes')->nullable();
            $t->text('reference');
            $t->foreignId('approved_by')->constrained('users');
            $t->timestamp('approved_at');
        });
        Schema::create('batch_recalls', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('product_id')->constrained();
            $t->string('batch_code', 80);
            $t->string('status');
            $t->unsignedInteger('version');
            $t->text('reason');
            $t->text('reference');
            $t->foreignId('issued_by')->constrained('users');
            $t->timestamp('issued_at');
            $t->foreignId('withdrawn_by')->nullable()->constrained('users');
            $t->timestamp('withdrawn_at')->nullable();
            $t->text('withdrawal_reason')->nullable();
        });
        Schema::table('inventory_batches', function (Blueprint $t) {
            $t->timestamp('ready_expires_at')->nullable();
        });
        Schema::create('inbox_entries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->foreignUlid('store_id')->nullable()->constrained();
            $t->string('event_key', 160);
            $t->string('event_type', 80);
            $t->string('title', 160);
            $t->string('local_path', 240);
            $t->json('roles');
            $t->timestamp('created_at');
            $t->timestamp('read_at')->nullable();
            $t->unique(['user_id', 'event_key']);
        });
        Schema::create('notification_preferences', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained();
            $t->boolean('email_enabled');
            $t->unsignedInteger('version');
            $t->timestamp('updated_at');
        });
        Schema::create('notification_deliveries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('inbox_entry_id')->unique()->constrained();
            $t->string('status');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at');
            $t->timestamp('lease_until')->nullable();
            $t->string('lease_token', 64)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->string('error_code')->nullable();
        });
        Schema::create('stock_warning_states', function (Blueprint $t) {
            $t->foreignUlid('store_id')->constrained();
            $t->foreignUlid('product_id')->constrained();
            $t->boolean('is_low');
            $t->unsignedInteger('episode')->default(0);
            $t->primary(['store_id', 'product_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('food_sops')->exists() || DB::table('batch_recalls')->exists() || DB::table('inbox_entries')->exists()) {
            throw new RuntimeException('Safety/notification history requires forward migration.');
        }foreach (['stock_warning_states', 'notification_deliveries', 'notification_preferences', 'inbox_entries', 'batch_recalls', 'food_sops'] as $table) {
            Schema::dropIfExists($table);
        }Schema::table('inventory_batches', fn (Blueprint $t) => $t->dropColumn('ready_expires_at'));
    }
};
