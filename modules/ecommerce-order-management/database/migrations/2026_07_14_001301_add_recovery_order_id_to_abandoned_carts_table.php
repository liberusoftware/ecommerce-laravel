<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AbandonedCart::markAsRecovered($order) writes recovery_order_id, but no
     * migration ever created the column -> the method fatals whenever a recovering
     * order is passed. Add it (nullable FK to orders).
     */
    public function up(): void
    {
        Schema::table('abandoned_carts', function (Blueprint $table) {
            if (! Schema::hasColumn('abandoned_carts', 'recovery_order_id')) {
                $table->foreignId('recovery_order_id')->nullable()->constrained('orders')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('abandoned_carts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recovery_order_id');
        });
    }
};
