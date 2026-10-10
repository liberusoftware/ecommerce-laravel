<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add pre-order fields to products
        if (!Schema::hasColumn('products', 'is_preorder')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('is_preorder')->default(false);
                $table->timestamp('preorder_available_from')->nullable();
                $table->timestamp('preorder_available_until')->nullable();
                $table->timestamp('preorder_release_date')->nullable();
                $table->integer('preorder_limit')->nullable(); // Max pre-order quantity
                $table->boolean('charge_upfront')->default(true); // Charge now or on release
                $table->text('preorder_message')->nullable();
            });
        }

        // Add pre-order fields to product variants
        if (Schema::hasTable('product_variants')) {
            if (!Schema::hasColumn('product_variants', 'is_preorder')) {
                Schema::table('product_variants', function (Blueprint $table) {
                    $table->boolean('is_preorder')->default(false);
                    $table->timestamp('preorder_release_date')->nullable();
                    $table->integer('preorder_limit')->nullable();
                });
            }
        }

        // Pre-order tracking
        Schema::create('preorders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('quantity');
            $table->timestamp('expected_release_date')->nullable();
            $table->string('status')->default('pending'); // pending, charged, released, cancelled
            $table->boolean('customer_notified')->default(false);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preorders');

        if (Schema::hasTable('product_variants')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->dropColumn(['is_preorder', 'preorder_release_date', 'preorder_limit']);
            });
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'is_preorder',
                'preorder_available_from',
                'preorder_available_until',
                'preorder_release_date',
                'preorder_limit',
                'charge_upfront',
                'preorder_message'
            ]);
        });
    }
};
