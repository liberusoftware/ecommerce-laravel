<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stock notifications table
        Schema::create('stock_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->boolean('notified')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->string('notification_type')->default('back_in_stock'); // back_in_stock, low_stock, price_drop
            $table->timestamps();

            $table->index(['product_id', 'notified']);
            $table->index(['email', 'notified']);
        });

        // Add backorder fields to products
        if (!Schema::hasColumn('products', 'allow_backorders')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('allow_backorders')->default(false);
                $table->string('stock_status')->default('in_stock'); // in_stock, out_of_stock, on_backorder
                $table->boolean('notify_on_restock')->default(true);
            });
        }

        // Add backorder fields to product variants
        if (Schema::hasTable('product_variants')) {
            if (!Schema::hasColumn('product_variants', 'allow_backorders')) {
                Schema::table('product_variants', function (Blueprint $table) {
                    $table->boolean('allow_backorders')->default(false);
                    $table->string('stock_status')->default('in_stock');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_variants')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->dropColumn(['allow_backorders', 'stock_status']);
            });
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['allow_backorders', 'stock_status', 'notify_on_restock']);
        });

        Schema::dropIfExists('stock_notifications');
    }
};
