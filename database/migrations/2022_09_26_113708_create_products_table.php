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
        if (! Schema::hasTable($this->table)) {
            Schema::create($this->table, function (Blueprint $table) {
                $table->id();
                $table->string('name');
                // Slug and SKU are unique per store, indexed with store_id.
                $table->string('slug')->nullable();
                $table->string('sku', 100)->nullable();
                $table->decimal('price', 10, 2)->nullable();
                $table->decimal('sale_price', 10, 2)->nullable();
                $table->text('description')->nullable();
                $table->text('short_description')->nullable();
                $table->foreignId('category_id')->nullable()->constrained('product_categories')->onUpdate('cascade')->onDelete('set null');
                // draft and archived are never shown or sold on a storefront.
                $table->string('status', 20)->default('active')->index();
                // hidden: purchasable by direct link, left out of listings and search.
                $table->string('visibility', 20)->default('visible');
                $table->boolean('is_featured')->default(0);
                $table->string('featured_image')->nullable();
                $table->string('meta_title')->nullable();
                $table->text('meta_description')->nullable();
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
