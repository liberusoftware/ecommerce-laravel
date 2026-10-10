<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_level_id')->constrained()->onDelete('cascade');
            $table->foreignId('inventory_item_id')->constrained()->onDelete('cascade');
            $table->integer('quantity_delta'); // Positive for increase, negative for decrease
            $table->string('reason')->nullable();
            $table->integer('available_after');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['inventory_level_id']);
            $table->index(['inventory_item_id']);
            $table->index(['reason']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
    }
};