<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ProductReview model marks these fillable and getHelpfulnessScore()/isVerifiedPurchase() read them;
// the original table only had `comments`.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_reviews')) {
            return;
        }

        Schema::table('product_reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('product_reviews', 'is_verified_purchase')) {
                $table->boolean('is_verified_purchase')->default(false)->after('comments');
            }
            foreach (['helpful_votes', 'unhelpful_votes'] as $col) {
                if (! Schema::hasColumn('product_reviews', $col)) {
                    $table->unsignedInteger($col)->default(0)->after('is_verified_purchase');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_reviews', function (Blueprint $table) {
            $table->dropColumn(['is_verified_purchase', 'helpful_votes', 'unhelpful_votes']);
        });
    }
};
