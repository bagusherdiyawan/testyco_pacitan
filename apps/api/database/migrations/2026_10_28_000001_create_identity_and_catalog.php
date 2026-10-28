<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('active')->default(true);
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('organizations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name', 120);
            $t->string('type', 20);
            $t->string('contact', 120)->nullable();
            $t->text('address')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('organization_user', function (Blueprint $t) {
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('role', 30);
            $t->unique(['organization_id', 'user_id']);
        });
        Schema::create('stores', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->text('address')->nullable();
            $t->string('region', 80)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('store_user', function (Blueprint $t) {
            $t->foreignUlid('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->unique(['store_id', 'user_id']);
        });
        Schema::create('audit_entries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignUlid('organization_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('action', 80);
            $t->string('subject_id', 80);
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->timestamp('created_at');
            $t->index(['organization_id', 'created_at']);
        });
        Schema::create('products', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->string('sku', 60);
            $t->string('name', 120);
            $t->string('variant', 80)->nullable();
            $t->string('condition', 20);
            $t->unsignedInteger('pack_size');
            $t->unsignedInteger('portion_size')->default(1);
            $t->unsignedBigInteger('wholesale_price');
            $t->unsignedBigInteger('retail_price');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'sku']);
        });
    }

    public function down(): void
    {
        foreach (['products', 'audit_entries', 'store_user', 'stores', 'organization_user', 'organizations', 'personal_access_tokens'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('active'));
    }
};
