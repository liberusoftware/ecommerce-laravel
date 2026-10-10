<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Loyalty programs table
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('points_per_dollar', 10, 2)->default(1.00); // Points earned per dollar spent
            $table->decimal('points_value', 10, 4)->default(0.01); // Dollar value per point
            $table->integer('points_expiry_days')->nullable(); // Days until points expire
            $table->integer('min_points_redemption')->default(100); // Minimum points to redeem
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Customer loyalty points balance
        Schema::create('loyalty_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loyalty_program_id')->constrained()->cascadeOnDelete();
            $table->integer('balance')->default(0);
            $table->integer('lifetime_earned')->default(0);
            $table->integer('lifetime_redeemed')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'loyalty_program_id']);
        });

        // Points transactions
        Schema::create('loyalty_point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_points_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('points');
            $table->string('type'); // earned, redeemed, expired, adjustment, bonus
            $table->text('description')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_expired')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['loyalty_points_id', 'created_at']);
            $table->index(['expires_at', 'is_expired']);
        });

        // Loyalty tiers/levels
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_program_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('min_points')->default(0); // Minimum points to reach tier
            $table->decimal('min_spend', 10, 2)->default(0); // Or minimum spend amount
            $table->decimal('points_multiplier', 5, 2)->default(1.00); // Earn extra points
            $table->decimal('discount_percentage', 5, 2)->default(0); // Tier discount
            $table->json('benefits')->nullable(); // Additional benefits
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Assign loyalty tier to customers
        if (Schema::hasTable('customers')) {
            if (!Schema::hasColumn('customers', 'loyalty_tier_id')) {
                Schema::table('customers', function (Blueprint $table) {
                    $table->foreignId('loyalty_tier_id')->nullable()->constrained()->nullOnDelete();
                });
            }
        }

        // Rewards catalog
        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_program_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('reward_type'); // discount_percentage, discount_amount, free_product, free_shipping
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->foreignId('free_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->integer('points_cost');
            $table->integer('max_redemptions')->nullable(); // Per customer
            $table->integer('stock_quantity')->nullable(); // Limited stock
            $table->boolean('is_active')->default(true);
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();
            $table->timestamps();
        });

        // Reward redemptions
        Schema::create('loyalty_reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_reward_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('points_spent');
            $table->string('status')->default('pending'); // pending, applied, expired, cancelled
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_reward_redemptions');
        Schema::dropIfExists('loyalty_rewards');

        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropForeign(['loyalty_tier_id']);
                $table->dropColumn('loyalty_tier_id');
            });
        }

        Schema::dropIfExists('loyalty_tiers');
        Schema::dropIfExists('loyalty_point_transactions');
        Schema::dropIfExists('loyalty_points');
        Schema::dropIfExists('loyalty_programs');
    }
};
