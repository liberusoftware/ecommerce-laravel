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

            // One cart store for everybody, so exactly one of these two is set.
            //
            // `user_id` is nullable because a guest's cart lives here too now —
            // this table used to hold accounts only, and guests kept a copy of
            // their cart in the session that nothing else could see. Two stores
            // meant two checkouts, and the session copy was the one the web
            // checkout charged from.
            //
            // `guest_token` is the session's stake in that row: an opaque value
            // handed out once per visitor, and the only thing that can claim an
            // unauthenticated cart. It is not a session id — a session id is a
            // credential, and this is written to a table read by staff tooling.
            // On login the guest's rows are folded into the account's and the
            // token is dropped, so a row never carries both.
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade')->onUpdate('cascade');
            $table->string('guest_token')->nullable()->index();
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
