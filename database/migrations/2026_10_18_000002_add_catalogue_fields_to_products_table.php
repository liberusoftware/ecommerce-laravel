<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sku', 100)->nullable()->after('slug');
            $table->decimal('sale_price', 10, 2)->nullable()->after('price');
            // draft and archived are never shown or sold on a storefront.
            $table->string('status', 20)->default('active')->index();
            // hidden: purchasable by direct link, left out of listings and search.
            $table->string('visibility', 20)->default('visible');

            // Slugs and SKUs are a store's own namespace; two merchants may both sell "blue-shirt".
            $table->unique(['store_id', 'slug']);
            $table->unique(['store_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'slug']);
            $table->dropUnique(['store_id', 'sku']);
            $table->dropIndex(['status']);
            $table->dropColumn(['sku', 'sale_price', 'status', 'visibility']);
        });
    }
};
