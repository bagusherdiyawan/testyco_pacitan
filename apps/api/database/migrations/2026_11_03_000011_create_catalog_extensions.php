<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_periods', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('partner_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $t->string('channel', 20);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->unsignedBigInteger('price');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->timestamps();
        });
        Schema::create('product_recipes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('product_id')->unique()->constrained()->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->boolean('active')->default(true);
            $t->json('components');
            $t->timestamps();
        });
        Schema::create('product_images', function (Blueprint $t) {
            $t->foreignUlid('product_id')->primary()->constrained()->restrictOnDelete();
            $t->string('path');
            $t->string('mime', 50);
            $t->string('sha256', 64);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::table('sale_lines', fn (Blueprint $t) => $t->json('recipe_snapshot')->nullable());
    }

    public function down(): void
    {
        foreach (['product_price_periods', 'product_recipes', 'product_images'] as $n) {
            if (DB::table($n)->exists()) {
                throw new RuntimeException('Rollback katalog terpakai ditolak.');
            }
        }Schema::table('sale_lines', fn (Blueprint $t) => $t->dropColumn('recipe_snapshot'));
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_recipes');
        Schema::dropIfExists('product_price_periods');
    }
};
