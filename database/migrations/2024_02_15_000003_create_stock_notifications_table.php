<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shoppers waiting for an out-of-stock product. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->boolean('notified')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->string('notification_type')->default('back_in_stock');
            $table->timestamps();

            $table->index(['product_id', 'notified']);
            $table->index(['email', 'notified']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_notifications');
    }
};
