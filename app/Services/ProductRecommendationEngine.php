<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductInteraction;
use App\Models\ProductRecommendation;
use App\Models\RecommendationRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Owns the stored recommendation set end to end: it captures the interactions
 * (`trackView` and friends write ProductInteraction rows), builds the
 * `product_recommendations` table from them by collaborative filtering — that half is
 * driven offline by the `recommendations:generate` command — and serves the result
 * back to requests. "Engine" rather than "reader" or "generator" because it is
 * genuinely both, and splitting the write half out would leave two classes over one
 * table with no caller asking for the seam.
 *
 * Its recommendations are cross-customer: what other people bought together, what is
 * trending this week. For suggestions drawn from a single shopper's own history, and
 * needing no precomputed table, see UserHistoryRecommender.
 */
class ProductRecommendationEngine
{
    /**
     * Get personalized recommendations for a user
     */
    public function getPersonalizedRecommendations(?int $userId, ?string $sessionId = null, int $limit = 10): Collection
    {
        if ($userId) {
            return $this->getUserRecommendations($userId, $limit);
        }

        if ($sessionId) {
            return $this->getSessionRecommendations($sessionId, $limit);
        }

        return $this->getTrendingProducts($limit);
    }

    /**
     * Get recommendations based on user history
     */
    protected function getUserRecommendations(int $userId, int $limit): Collection
    {
        // Get products user has interacted with
        $viewedProductIds = ProductInteraction::where('user_id', $userId)
            ->byType('view')
            ->recent(90)
            ->pluck('product_id')
            ->unique()
            ->take(20);

        if ($viewedProductIds->isEmpty()) {
            return $this->getTrendingProducts($limit);
        }

        // Don't recommend products the user already bought.
        $purchasedProductIds = ProductInteraction::where('user_id', $userId)
            ->byType('purchase')
            ->pluck('product_id')
            ->unique();

        // Highest score first, then dedup by product and cap at $limit
        // (a product can be recommended by several viewed products).
        return ProductRecommendation::whereIn('product_id', $viewedProductIds)
            ->when(
                $purchasedProductIds->isNotEmpty(),
                fn ($q) => $q->whereNotIn('recommended_product_id', $purchasedProductIds)
            )
            ->with('recommendedProduct')
            ->orderByDesc('score')
            ->get()
            ->pluck('recommendedProduct')
            ->filter()
            ->unique('id')
            ->take($limit)
            ->values();
    }

    /**
     * Get recommendations based on session
     */
    protected function getSessionRecommendations(string $sessionId, int $limit): Collection
    {
        $viewedProductIds = ProductInteraction::where('session_id', $sessionId)
            ->recent(1)
            ->pluck('product_id')
            ->unique();

        if ($viewedProductIds->isEmpty()) {
            return $this->getTrendingProducts($limit);
        }

        return ProductRecommendation::whereIn('product_id', $viewedProductIds)
            ->with('recommendedProduct')
            ->orderByDesc('score')
            ->get()
            ->pluck('recommendedProduct')
            ->filter()
            ->unique('id')
            ->take($limit)
            ->values();
    }

    /**
     * Get trending products
     */
    public function getTrendingProducts(int $limit = 10): Collection
    {
        return Product::select('products.*')
            ->join('product_interactions', 'products.id', '=', 'product_interactions.product_id')
            ->where('product_interactions.interacted_at', '>=', now()->subDays(7))
            ->groupBy('products.id')
            ->orderByRaw('COUNT(product_interactions.id) DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * Get "Customers also bought" recommendations
     */
    public function getAlsoBoughtRecommendations(int $productId, int $limit = 6): Collection
    {
        // Find products purchased together
        $recommendations = DB::table('order_items as oi1')
            ->join('order_items as oi2', 'oi1.order_id', '=', 'oi2.order_id')
            ->join('products', 'oi2.product_id', '=', 'products.id')
            ->where('oi1.product_id', $productId)
            ->where('oi2.product_id', '!=', $productId)
            ->select('products.*', DB::raw('COUNT(*) as frequency'))
            ->groupBy('products.id')
            ->orderByDesc('frequency')
            ->limit($limit)
            ->get();

        return Product::hydrate($recommendations->toArray());
    }

    /**
     * Get similar products based on category and attributes
     */
    public function getSimilarProducts(Product $product, int $limit = 6): Collection
    {
        return Product::where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->whereBetween('price', [
                $product->price * 0.7,
                $product->price * 1.3,
            ])
            ->inRandomOrder()
            ->limit($limit)
            ->get();
    }

    /**
     * Generate recommendations using collaborative filtering
     */
    public function generateCollaborativeRecommendations(): void
    {
        $rule = RecommendationRule::firstOrCreate(
            ['type' => 'collaborative'],
            [
                'name' => 'Collaborative Filtering',
                'is_active' => true,
                'priority' => 1,
            ]
        );

        // Get products frequently purchased together
        $cooccurrences = DB::table('order_items as oi1')
            ->join('order_items as oi2', 'oi1.order_id', '=', 'oi2.order_id')
            ->where('oi1.product_id', '!=', DB::raw('oi2.product_id'))
            ->select(
                'oi1.product_id',
                'oi2.product_id as recommended_product_id',
                DB::raw('COUNT(*) as frequency')
            )
            ->groupBy('oi1.product_id', 'oi2.product_id')
            ->having('frequency', '>=', 3)
            ->get();

        foreach ($cooccurrences as $pair) {
            // Calculate score (0-1) based on frequency
            $maxFrequency = 100; // Assume max frequency
            $score = min(1, $pair->frequency / $maxFrequency);

            ProductRecommendation::updateOrCreate(
                [
                    'product_id' => $pair->product_id,
                    'recommended_product_id' => $pair->recommended_product_id,
                ],
                [
                    'rule_id' => $rule->id,
                    'score' => $score,
                    'reason' => 'Frequently bought together',
                ]
            );
        }
    }

    /**
     * Track product view for recommendations
     */
    public function trackView(?int $userId, int $productId, ?int $duration = null): void
    {
        ProductInteraction::track(
            $userId,
            session()->getId(),
            $productId,
            'view',
            $duration
        );
    }

    /**
     * Track add to cart for recommendations
     */
    public function trackAddToCart(?int $userId, int $productId): void
    {
        ProductInteraction::track(
            $userId,
            session()->getId(),
            $productId,
            'add_to_cart'
        );
    }

    /**
     * Track purchase for recommendations
     */
    public function trackPurchase(?int $userId, int $productId): void
    {
        ProductInteraction::track(
            $userId,
            session()->getId(),
            $productId,
            'purchase'
        );
    }
}
