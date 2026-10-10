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
                $table->string('slug')->nullable()->unique();
                $table->decimal('price', 10, 2)->nullable();
                $table->text('description')->nullable();
                $table->text('short_description')->nullable();
                $table->text('long_description')->nullable();
                $table->foreignId('category_id')->nullable()->constrained('product_categories')->onUpdate('cascade')->onDelete('set null');
                $table->boolean('is_variable')->default(0);
                $table->boolean('is_grouped')->default(0);
                $table->boolean('is_simple')->default(1);
                $table->boolean('is_featured')->default(0);
                $table->string('featured_image')->nullable();
                $table->string('meta_title')->nullable();
                $table->text('meta_description')->nullable();
                $table->string('meta_keywords')->nullable();
                $table->boolean('is_downloadable')->default(0);
                $table->string('downloadable_file')->nullable();
                $table->integer('download_limit')->nullable();
                $table->dateTime('expiration_time')->nullable();
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
