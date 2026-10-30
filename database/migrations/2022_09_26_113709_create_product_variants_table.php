<?php

use App\Support\PreReleaseSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An existing database runs this file alone, without the in-place edits that go with it.
        PreReleaseSchema::assertCurrent([
            'products' => ['variant_attributes'],
            'cart_items' => ['product_variant_id'],
            'order_items' => ['product_variant_id'],
            'inventory_logs' => ['product_variant_id'],
        ]);

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            // [{"name": "Colour", "value": "Black"}, ...] in the product's attribute order.
            $table->json('attribute_values');
            // Null once removed, so the combination can be offered again.
            $table->string('combination_key', 40)->nullable();
            $table->string('sku', 100)->nullable();
            $table->decimal('price', 10, 2);
            $table->boolean('track_inventory')->default(true);
            $table->integer('inventory_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['product_id', 'combination_key']);
            $table->unique(['store_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
