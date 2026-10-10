<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->onDelete('cascade');
            $table->foreignId('location_id')->constrained('inventory_locations')->onDelete('cascade');
            $table->integer('available')->default(0);
            $table->integer('committed')->default(0);
            $table->integer('incoming')->default(0);
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);
            $table->timestamps();

            $table->unique(['inventory_item_id', 'location_id']);
            $table->index(['inventory_item_id']);
            $table->index(['location_id']);
            $table->index(['available']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_levels');
    }
};