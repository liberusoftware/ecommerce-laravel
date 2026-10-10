<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_fulfillment_service')->default(false);
            $table->boolean('fulfills_online_orders')->default(true);
            $table->boolean('fulfills_local_delivery')->default(false);
            $table->boolean('fulfills_pickup')->default(false);
            $table->boolean('legacy')->default(false);
            $table->string('admin_graphql_api_id')->nullable();
            $table->timestamps();

            $table->index(['is_active']);
            $table->index(['fulfills_online_orders']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_locations');
    }
};