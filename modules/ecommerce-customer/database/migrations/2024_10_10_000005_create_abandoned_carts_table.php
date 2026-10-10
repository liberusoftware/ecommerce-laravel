<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abandoned_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null');
            $table->string('customer_email');
            $table->string('session_id');
            $table->string('cart_token')->unique();
            $table->decimal('total_amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->timestamp('abandoned_at');
            $table->timestamp('recovered_at')->nullable();
            $table->timestamp('recovery_email_sent_at')->nullable();
            $table->integer('recovery_email_count')->default(0);
            $table->text('checkout_url')->nullable();
            $table->json('line_items');
            $table->string('customer_locale')->default('en');
            $table->json('billing_address')->nullable();
            $table->json('shipping_address')->nullable();
            $table->timestamps();

            $table->index(['customer_id']);
            $table->index(['customer_email']);
            $table->index(['abandoned_at']);
            $table->index(['recovered_at']);
            $table->index(['cart_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abandoned_carts');
    }
};