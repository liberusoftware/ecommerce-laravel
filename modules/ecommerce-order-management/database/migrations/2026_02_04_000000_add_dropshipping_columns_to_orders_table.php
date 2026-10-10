<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Add dropshipping related columns without using ->after() to avoid
            // relying on a specific existing column order which can fail when
            // running migrations in different environments or on existing DBs.
            // Guard each add with hasColumn so a partial/re-applied schema does
            // not fatal with a duplicate-column error (matches the guarded
            // sibling migrations 2026_02_04_000001 and 2026_03_10_000000).
            if (! Schema::hasColumn('orders', 'supplier_id')) {
                $table->string('supplier_id')->nullable();
            }
            if (! Schema::hasColumn('orders', 'supplier_order_reference')) {
                $table->string('supplier_order_reference')->nullable();
            }
            if (! Schema::hasColumn('orders', 'supplier_tracking_number')) {
                $table->string('supplier_tracking_number')->nullable();
            }
            if (! Schema::hasColumn('orders', 'supplier_response')) {
                $table->text('supplier_response')->nullable();
            }
            if (! Schema::hasColumn('orders', 'shipping_method_id')) {
                $table->unsignedBigInteger('shipping_method_id')->nullable();
            }
            if (! Schema::hasColumn('orders', 'is_dropshipped')) {
                $table->boolean('is_dropshipped')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'supplier_id',
                'supplier_order_reference',
                'supplier_tracking_number',
                'supplier_response',
                'shipping_method_id',
                'is_dropshipped',
            ]);
        });
    }
};
