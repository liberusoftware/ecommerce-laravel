<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Product recommendations configuration
        Schema::create('recommendation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // collaborative, content_based, trending, personalized
            $table->json('configuration')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->timestamps();
        });

        // Store product-to-product recommendations
        Schema::create('product_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('recommended_product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('rule_id')->nullable()->constrained('recommendation_rules')->onDelete('set null');
            $table->decimal('score', 5, 4)->default(0); // Recommendation score 0-1
            $table->string('reason')->nullable(); // Why this is recommended
            $table->timestamps();
            
            $table->unique(['product_id', 'recommended_product_id']);
            $table->index(['product_id', 'score']);
        });

        // Track user interactions for personalization
        Schema::create('product_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('session_id')->nullable(); // For guest users
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->enum('interaction_type', ['view', 'add_to_cart', 'purchase', 'wishlist', 'review']);
            $table->integer('duration')->nullable(); // Time spent viewing (seconds)
            $table->json('metadata')->nullable();
            $table->timestamp('interacted_at')->useCurrent();
            
            $table->index(['user_id', 'interaction_type']);
            $table->index(['product_id', 'interaction_type']);
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_interactions');
        Schema::dropIfExists('product_recommendations');
        Schema::dropIfExists('recommendation_rules');
    }
};
