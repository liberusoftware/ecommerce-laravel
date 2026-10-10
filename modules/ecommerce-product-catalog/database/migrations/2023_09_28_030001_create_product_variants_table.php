<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('sku')->nullable()->unique();
            $table->string('title')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('compare_at_price', 10, 2)->nullable();
            $table->integer('inventory_quantity')->default(0);
            $table->enum('inventory_policy', ['deny', 'continue'])->default('deny');
            $table->string('fulfillment_service')->default('manual');
            $table->string('inventory_management')->nullable();
            $table->string('option1')->nullable();
            $table->string('option2')->nullable();
            $table->string('option3')->nullable();
            $table->boolean('taxable')->default(true);
            $table->string('barcode')->nullable();
            $table->integer('grams')->nullable();
            $table->foreignId('image_id')->nullable()->constrained('product_images')->onDelete('set null');
            $table->decimal('weight', 8, 2)->nullable();
            $table->string('weight_unit')->default('kg');
            $table->foreignId('inventory_item_id')->nullable()->constrained()->onDelete('set null');
            $table->integer('old_inventory_quantity')->nullable();
            $table->boolean('requires_shipping')->default(true);
            $table->integer('position')->default(1);
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};