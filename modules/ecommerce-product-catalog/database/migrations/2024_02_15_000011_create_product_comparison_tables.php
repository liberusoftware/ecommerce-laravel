<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Product comparison lists
        Schema::create('product_comparisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('session_id')->nullable();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'product_id']);
            $table->index(['session_id', 'product_id']);
        });

        // Comparison attributes to display
        Schema::create('comparison_attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('attribute_key'); // The key to extract from product
            $table->string('attribute_type')->default('text'); // text, number, boolean, price, image
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comparison_attributes');
        Schema::dropIfExists('product_comparisons');
    }
};
