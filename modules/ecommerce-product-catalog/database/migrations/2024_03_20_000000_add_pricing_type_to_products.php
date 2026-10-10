<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('pricing_type', ['fixed', 'free', 'donation'])->default('fixed');
            $table->decimal('suggested_price', 10, 2)->nullable();
            $table->decimal('minimum_price', 10, 2)->default(0.00);
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['pricing_type', 'suggested_price', 'minimum_price']);
        });
    }
};