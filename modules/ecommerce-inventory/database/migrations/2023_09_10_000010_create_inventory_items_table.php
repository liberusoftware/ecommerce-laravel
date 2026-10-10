<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            //$table->foreignId('product_variant_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('sku')->nullable();
            $table->decimal('cost', 10, 2)->nullable();
            $table->string('country_code_of_origin', 2)->nullable();
            $table->string('province_code_of_origin')->nullable();
            $table->string('harmonized_system_code')->nullable();
            $table->boolean('tracked')->default(true);
            $table->json('country_harmonized_system_codes')->nullable();
            $table->boolean('requires_shipping')->default(true);
            $table->timestamps();

            $table->index(['product_id']);
            //$table->index(['product_variant_id']);
            $table->index(['sku']);
            $table->index(['tracked']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};