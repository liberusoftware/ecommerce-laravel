<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $table = 'order_items';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onUpdate('cascade')->onDelete('cascade');
            // The line outlives its product: name, options and price are a snapshot.
            $table->foreignId('product_id')->nullable()->constrained()->onUpdate('cascade')->nullOnDelete();
            $table->string('product_name')->nullable();
            $table->json('options')->nullable();
            $table->integer('quantity');
            $table->decimal('price', 10, 2);
            $table->string('download_link')->nullable();
            $table->timestamp('download_expires_at')->nullable();
            $table->integer('download_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};
