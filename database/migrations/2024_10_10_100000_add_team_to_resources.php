<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $tables = [
        'product_categories', 'products', 'payment_methods', 'customers', 'wishlists', 'orders',
        'coupons', 'groups', 'product_reviews', 'downloadable_products', 'images', 'cart_items',
        'collections',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {

        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'team_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->foreignId('team_id')->nullable()->constrained()->onDelete('cascade');
                });
            }
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'team_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropForeign(['team_id']);
                    $table->dropColumn('team_id');
                });
            }
        }
    }
};
