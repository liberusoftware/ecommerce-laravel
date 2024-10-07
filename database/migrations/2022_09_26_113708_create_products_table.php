<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $table = 'products';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable($this->table)) {
            Schema::create($this->table, function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->decimal('price', 10, 2);
                $table->text('short_description');
                $table->text('long_description');
                $table->foreignId('category_id')->constrained('product_categories')->onUpdate('cascade')->onDelete('cascade');
                
                // Relation avec Vehicle Model
                $table->unsignedBigInteger('vehicle_model_id')->nullable();
                $table->foreign('vehicle_model_id')->references('id')->on('vehicle_models')->onDelete('cascade');
                
                // Relation avec Brand
                $table->unsignedBigInteger('brand_id')->nullable();
                $table->foreign('brand_id')->references('id')->on('brands')->onDelete('cascade');
                
                $table->boolean('is_variable')->default(0);
                $table->boolean('is_grouped')->default(0);
                $table->boolean('is_simple')->default(1);
                $table->boolean('is_featured')->default(0);
                $table->string('featured_image');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};
