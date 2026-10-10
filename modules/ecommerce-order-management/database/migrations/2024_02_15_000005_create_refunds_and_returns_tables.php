<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Refunds table
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected, processed
            $table->string('refund_method')->nullable(); // original_payment, store_credit, manual
            $table->string('transaction_id')->nullable(); // Payment gateway transaction ID
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->boolean('restock_items')->default(true);
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        // Refund line items (which items are being refunded)
        Schema::create('refund_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity');
            $table->decimal('amount', 10, 2);
            $table->boolean('restock')->default(true);
            $table->timestamps();
        });

        // Return Merchandise Authorization (RMA)
        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->string('rma_number')->unique();
            $table->string('reason');
            $table->text('description')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected, received, completed
            $table->string('return_method')->nullable(); // ship, drop_off
            $table->string('tracking_number')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('rma_number');
        });

        // Return request items
        Schema::create('return_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity');
            $table->string('condition')->nullable(); // unopened, opened, damaged
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Add refund-related fields to orders
        if (!Schema::hasColumn('orders', 'refund_total')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('refund_total', 10, 2)->default(0);
                $table->boolean('fully_refunded')->default(false);
                $table->boolean('partially_refunded')->default(false);
            });
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['refund_total', 'fully_refunded', 'partially_refunded']);
        });

        Schema::dropIfExists('return_request_items');
        Schema::dropIfExists('return_requests');
        Schema::dropIfExists('refund_items');
        Schema::dropIfExists('refunds');
    }
};
