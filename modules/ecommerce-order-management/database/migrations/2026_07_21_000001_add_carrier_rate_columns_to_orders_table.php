<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record which live carrier rate an order shipped on (when the buyer chose one over a
 * flat method). shipping_cost already holds the amount; these name the carrier/service
 * and link back to the quote that priced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'shipping_carrier')) {
                $table->string('shipping_carrier')->nullable()->after('shipping_method_id');
            }
            if (! Schema::hasColumn('orders', 'shipping_service')) {
                $table->string('shipping_service')->nullable()->after('shipping_carrier');
            }
            if (! Schema::hasColumn('orders', 'shipping_quote_id')) {
                $table->foreignId('shipping_quote_id')->nullable()->after('shipping_service')
                    ->constrained('shipping_quotes')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'shipping_quote_id')) {
                $table->dropConstrainedForeignId('shipping_quote_id');
            }
            foreach (['shipping_carrier', 'shipping_service'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
