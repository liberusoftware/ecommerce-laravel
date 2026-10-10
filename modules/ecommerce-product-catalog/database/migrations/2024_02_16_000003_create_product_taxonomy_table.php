<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global product taxonomy for standardized categorization
        Schema::create('taxonomy_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('taxonomy_categories')->onDelete('cascade');
            $table->integer('level')->default(0);
            $table->string('path')->nullable(); // Materialized path for hierarchy
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            
            $table->index(['parent_id', 'sort_order']);
            $table->index('path');
        });

        // Product taxonomy assignments
        Schema::create('product_taxonomy', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('taxonomy_category_id')->constrained()->onDelete('cascade');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            
            $table->unique(['product_id', 'taxonomy_category_id']);
            $table->index('is_primary');
        });

        // Taxonomy attributes (additional structured data)
        Schema::create('taxonomy_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taxonomy_category_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('slug');
            $table->enum('type', ['text', 'number', 'boolean', 'select', 'multiselect']);
            $table->json('options')->nullable(); // For select/multiselect
            $table->boolean('is_required')->default(false);
            $table->boolean('is_filterable')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            
            $table->index(['taxonomy_category_id', 'slug']);
        });

        // Product taxonomy attribute values
        Schema::create('product_taxonomy_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('taxonomy_attribute_id')->constrained()->onDelete('cascade');
            $table->text('value');
            $table->timestamps();
            
            $table->unique(['product_id', 'taxonomy_attribute_id']);
            // $table->index('value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_taxonomy_values');
        Schema::dropIfExists('taxonomy_attributes');
        Schema::dropIfExists('product_taxonomy');
        Schema::dropIfExists('taxonomy_categories');
    }
};
