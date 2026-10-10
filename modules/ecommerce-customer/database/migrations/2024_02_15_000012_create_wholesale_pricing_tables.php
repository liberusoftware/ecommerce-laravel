<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wholesale customer groups
        Schema::create('wholesale_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->boolean('hide_retail_price')->default(false);
            $table->boolean('requires_approval')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Assign wholesale group to customers
        if (Schema::hasTable('customers')) {
            if (!Schema::hasColumn('customers', 'wholesale_group_id')) {
                Schema::table('customers', function (Blueprint $table) {
                    $table->foreignId('wholesale_group_id')->nullable()->constrained()->nullOnDelete();
                    $table->boolean('is_wholesale')->default(false);
                    $table->string('wholesale_status')->default('pending'); // pending, approved, rejected
                    $table->text('business_name')->nullable();
                    $table->string('tax_id')->nullable();
                });
            }
        }

        // Wholesale pricing tiers
        Schema::create('wholesale_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('wholesale_group_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('min_quantity');
            $table->integer('max_quantity')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('discount_percentage', 5, 2)->nullable();
            $table->timestamps();

            $table->index(['product_id', 'wholesale_group_id']);
        });

        // Minimum order quantities
        if (!Schema::hasColumn('products', 'min_order_quantity')) {
            Schema::table('products', function (Blueprint $table) {
                $table->integer('min_order_quantity')->default(1);
                $table->integer('max_order_quantity')->nullable();
                $table->integer('wholesale_min_quantity')->nullable();
            });
        }

        // Quote requests for B2B
        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('quote_number')->unique();
            $table->string('status')->default('pending'); // pending, sent, accepted, rejected, expired
            $table->json('items'); // Cart items for quote
            $table->text('notes')->nullable();
            $table->text('response_notes')->nullable();
            $table->decimal('quoted_total', 10, 2)->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_requests');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['min_order_quantity', 'max_order_quantity', 'wholesale_min_quantity']);
        });

        Schema::dropIfExists('wholesale_price_tiers');

        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropForeign(['wholesale_group_id']);
                $table->dropColumn(['wholesale_group_id', 'is_wholesale', 'wholesale_status', 'business_name', 'tax_id']);
            });
        }

        Schema::dropIfExists('wholesale_groups');
    }
};
