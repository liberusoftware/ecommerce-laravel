<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who fulfils a product, and the supplier's own ids for it and each variant.
        Schema::table('products', function (Blueprint $table) {
            $table->string('supplier_id', 50)->nullable();
            $table->string('supplier_product_id')->nullable();
            $table->unique(['store_id', 'supplier_id', 'supplier_product_id'], 'products_supplier_product_unique');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('supplier_variant_id')->nullable();
            $table->unique(['store_id', 'supplier_variant_id'], 'variants_supplier_variant_unique');
        });

        // Who the parcel is for and where, in parts, which a supplier needs and a free-text address cannot give it.
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_name', 100)->nullable();
            $table->string('shipping_city', 100)->nullable();
            $table->string('shipping_state', 100)->nullable();
            $table->string('shipping_postal_code', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_name', 'shipping_city', 'shipping_state', 'shipping_postal_code']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique('variants_supplier_variant_unique');
            $table->dropColumn('supplier_variant_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_supplier_product_unique');
            $table->dropColumn(['supplier_id', 'supplier_product_id']);
        });
    }
};
