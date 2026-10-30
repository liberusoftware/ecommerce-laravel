<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInventoryLogsTable extends Migration
{
    public function up()
    {
        Schema::create('inventory_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            // Set when the stock moved was a variant's rather than the product's.
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('quantity_change');
            $table->text('reason');
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('inventory_logs');
    }
}
