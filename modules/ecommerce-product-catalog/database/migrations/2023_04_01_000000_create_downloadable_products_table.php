<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDownloadableProductsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
       
        Schema::create('downloadable_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('file_url');
            $table->integer('download_limit');
            $table->dateTime('expiration_time')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('downloadable_products');
    }
}
