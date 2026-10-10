<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            // Unique per team rather than per installation — see the coupons
            // table for the same correction. The composite is added in
            // 2026_08_09_000002, once `team_id` exists.
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->enum('type', ['percentage', 'fixed_amount', 'free_shipping', 'buy_x_get_y']);
            $table->decimal('value', 10, 2);
            $table->enum('target_type', ['order', 'product', 'collection', 'shipping']);
            $table->json('target_selection')->nullable();
            $table->json('minimum_requirements')->nullable();
            $table->json('customer_eligibility')->nullable();
            $table->json('usage_limits')->nullable();
            $table->json('active_dates')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('applies_once_per_customer')->default(false);
            $table->boolean('applies_to_each_item')->default(false);
            $table->foreignId('customer_group_id')->nullable()->constrained()->onDelete('set null');
            $table->json('prerequisite_subtotal_range')->nullable();
            $table->json('prerequisite_quantity_range')->nullable();
            $table->json('prerequisite_shipping_price_range')->nullable();
            $table->json('entitled_product_ids')->nullable();
            $table->json('entitled_collection_ids')->nullable();
            $table->json('entitled_country_ids')->nullable();
            $table->json('prerequisite_product_ids')->nullable();
            $table->json('prerequisite_collection_ids')->nullable();
            $table->json('prerequisite_customer_ids')->nullable();
            $table->string('allocation_method')->default('across');
            $table->boolean('once_per_customer')->default(false);
            $table->integer('usage_limit')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['code']);
            $table->index(['is_active']);
            $table->index(['starts_at']);
            $table->index(['ends_at']);
            $table->index(['type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
