<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            // Not unique here. A coupon is one merchant's money, and a globally
            // unique code means the first merchant to issue SUMMER10 takes it
            // from everyone else on the installation. The uniqueness that is
            // actually wanted is per store, and `store_id` does not exist until
            // 2026_08_08_000002 — so the composite lands there, in
            // 2026_08_09_000002. Indexed, because the code is the lookup key
            // and the orders join reads it.
            $table->string('code')->index();
            $table->enum('type', ['percentage', 'fixed']);
            $table->decimal('value', 10, 2);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->integer('max_uses')->nullable();
            $table->decimal('min_purchase_amount', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('coupons');
    }
};
