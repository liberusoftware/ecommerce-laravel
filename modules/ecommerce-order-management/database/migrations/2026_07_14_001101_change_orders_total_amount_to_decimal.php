<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * orders.total_amount was an integer column while Order casts it decimal:2.
     * On MySQL the integer column truncates the cents of every order total
     * (99.99 -> 100). sqlite's loose typing masks it, so the test suite never
     * caught it. Widen the column to decimal(10,2) to match the cast and the
     * sibling money columns (shipping_cost, tax_amount, discount_amount).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('total_amount', 10, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->integer('total_amount')->change();
        });
    }
};
