<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A shared wishlist is per-user (all of that user's items), but share_token
     * lived on each wishlist row with a UNIQUE index — so sharing 2+ items wrote
     * the same token to every row and hit the unique constraint. Move the token
     * to the owning user.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('wishlist_share_token')->nullable()->unique();
        });

        Schema::table('wishlists', function (Blueprint $table) {
            $table->dropUnique(['share_token']);
            $table->dropColumn('share_token');
        });
    }

    public function down(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {
            $table->string('share_token')->nullable()->unique();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['wishlist_share_token']);
            $table->dropColumn('wishlist_share_token');
        });
    }
};
