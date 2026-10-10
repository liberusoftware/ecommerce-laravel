<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ProductRating model writes/aggregates these breakdown columns; the original table only had `rating`.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_rating')) {
            return;
        }

        Schema::table('product_rating', function (Blueprint $table) {
            foreach (['overall_rating', 'quality_rating', 'value_rating', 'price_rating'] as $col) {
                if (! Schema::hasColumn('product_rating', $col)) {
                    $table->integer($col)->nullable()->after('rating');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_rating', function (Blueprint $table) {
            $table->dropColumn(['overall_rating', 'quality_rating', 'value_rating', 'price_rating']);
        });
    }
};
