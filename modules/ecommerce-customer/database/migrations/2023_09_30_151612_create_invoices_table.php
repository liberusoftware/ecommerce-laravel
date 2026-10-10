<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $table = 'invoices';
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->references('id')->on('customers')->onDelete('cascade');
            $table->foreignId('order_id')->constrained()->references('id')->on('orders')->onDelete('cascade');
            $table->timestamp('invoice_date');
            $table->decimal('total_amount', 10, 2);
            $table->string('payment_status', 50);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice');
    }
};
