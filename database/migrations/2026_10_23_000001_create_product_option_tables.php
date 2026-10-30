<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->index();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->string('name');
            $table->unsignedInteger('min_select')->default(0);
            // Null means no upper limit.
            $table->unsignedInteger('max_select')->nullable();
            $table->timestamps();
        });

        Schema::create('product_option_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_option_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price_adjustment', 10, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('product_option_group_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_option_group_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->unique(['product_id', 'product_option_group_id'], 'product_option_group_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_option_group_product');
        Schema::dropIfExists('product_option_choices');
        Schema::dropIfExists('product_option_groups');
    }
};
