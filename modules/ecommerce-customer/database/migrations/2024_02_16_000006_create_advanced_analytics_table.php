<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer Lifetime Value tracking
        Schema::create('customer_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade');
            $table->decimal('lifetime_value', 10, 2)->default(0);
            $table->decimal('average_order_value', 10, 2)->default(0);
            $table->integer('total_orders')->default(0);
            $table->integer('total_items_purchased')->default(0);
            $table->timestamp('first_purchase_at')->nullable();
            $table->timestamp('last_purchase_at')->nullable();
            $table->integer('days_since_last_purchase')->nullable();
            $table->decimal('predicted_next_order_value', 10, 2)->nullable();
            $table->date('predicted_next_order_date')->nullable();
            $table->enum('customer_segment', ['new', 'active', 'at_risk', 'churned', 'vip'])->default('new');
            $table->integer('retention_score')->default(0); // 0-100
            $table->timestamps();
        });

        // Conversion funnel tracking
        Schema::create('conversion_funnels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('steps'); // Define funnel steps
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('conversion_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funnel_id')->constrained('conversion_funnels')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('session_id');
            $table->string('step_name');
            $table->integer('step_order');
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            
            $table->index(['funnel_id', 'session_id']);
            $table->index('occurred_at');
        });

        // A/B Testing framework
        Schema::create('ab_tests', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['page', 'feature', 'price', 'content', 'checkout']);
            $table->json('variants'); // Define test variants
            $table->decimal('traffic_allocation', 5, 2)->default(100.00); // % of users
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->enum('status', ['draft', 'running', 'paused', 'completed'])->default('draft');
            $table->string('winning_variant')->nullable();
            $table->timestamps();
        });

        Schema::create('ab_test_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_id')->constrained('ab_tests')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('session_id');
            $table->string('variant_name');
            $table->timestamp('assigned_at')->useCurrent();
            $table->boolean('converted')->default(false);
            $table->timestamp('converted_at')->nullable();
            $table->decimal('conversion_value', 10, 2)->nullable();
            
            $table->unique(['test_id', 'session_id']);
            $table->index(['test_id', 'variant_name']);
        });

        // Product performance metrics
        Schema::create('product_performance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->integer('views')->default(0);
            $table->integer('add_to_cart')->default(0);
            $table->integer('purchases')->default(0);
            $table->decimal('revenue', 10, 2)->default(0);
            $table->decimal('conversion_rate', 5, 2)->default(0);
            $table->integer('returns')->default(0);
            $table->decimal('return_rate', 5, 2)->default(0);
            $table->timestamps();
            
            $table->unique(['product_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_performance');
        Schema::dropIfExists('ab_test_assignments');
        Schema::dropIfExists('ab_tests');
        Schema::dropIfExists('conversion_events');
        Schema::dropIfExists('conversion_funnels');
        Schema::dropIfExists('customer_metrics');
    }
};
