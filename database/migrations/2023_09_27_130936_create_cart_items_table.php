<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $table = 'cart_items';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->id();
            // No `session_id`. `user_id` below is a required foreign key, so a
            // cart item always belongs to an account and never to a session —
            // guests are not persisted at all. The column was written by every
            // path and read by none, which left the API filling it with the
            // literal string 'api' because it could not be left empty.
            // `abandoned_carts` keeps its own `session_id`, and there it is
            // load-bearing: an abandoned cart is usually a guest's.
            $table->foreignId('user_id')->constrained()->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade')->onUpdate('cascade');
            $table->integer('quantity');
            $table->decimal('price', 10, 2);
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
