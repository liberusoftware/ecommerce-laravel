<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The InventoryLog model's $fillable and every writer (CheckoutController,
     * InventoryController, ProductResource) record old_quantity, new_quantity and
     * a polymorphic reference, but the create_inventory_logs_table migration only
     * created product_id/quantity_change/reason — so every inventory log insert
     * fatals. This adds the missing columns.
     */
    public function up(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_logs', 'old_quantity')) {
                $table->integer('old_quantity')->nullable();
            }
            if (! Schema::hasColumn('inventory_logs', 'new_quantity')) {
                $table->integer('new_quantity')->nullable();
            }
            if (! Schema::hasColumn('inventory_logs', 'reference_id')) {
                $table->unsignedBigInteger('reference_id')->nullable();
            }
            if (! Schema::hasColumn('inventory_logs', 'reference_type')) {
                $table->string('reference_type')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropColumn(['old_quantity', 'new_quantity', 'reference_id', 'reference_type']);
        });
    }
};
