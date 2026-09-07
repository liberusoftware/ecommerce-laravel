<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInteraction;
use App\Models\ProductRecommendation;
use App\Models\User;
use App\Services\ProductRecommendationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductRecommendationEngineTest extends TestCase
{
    use RefreshDatabase;

    private ProductRecommendationEngine $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductRecommendationEngine::class);
    }

    private function makeProduct(array $overrides = []): Product
    {
        $category = ProductCategory::create([
            'name' => 'Rec Category',
            'slug' => 'rec-cat-'.uniqid(),
        ]);

        return Product::create(array_merge([
            'name' => 'Rec Product',
            'slug' => 'rec-prod-'.uniqid(),
            'price' => 25.00,
            'category_id' => $category->id,
            'inventory_count' => 5,
        ], $overrides));
    }

    public function test_service_can_be_resolved(): void
    {
        $this->assertInstanceOf(ProductRecommendationEngine::class, $this->service);
    }

    public function test_product_recommendation_model_can_be_created(): void
    {
        $product = $this->makeProduct();
        $recommended = $this->makeProduct();

        $rec = ProductRecommendation::create([
            'product_id' => $product->id,
            'recommended_product_id' => $recommended->id,
            'score' => 85.5,
            'reason' => 'frequently_bought_together',
        ]);

        $this->assertInstanceOf(ProductRecommendation::class, $rec);
        $this->assertEquals(85.5, $rec->score);
    }

    public function test_user_recommendations_dedup_and_exclude_purchased(): void
    {
        $user = User::factory()->create();
        $viewedA = $this->makeProduct();
        $viewedB = $this->makeProduct();
        $recommended = $this->makeProduct();
        $purchased = $this->makeProduct();

        // User viewed A and B, and already purchased $purchased.
        foreach ([$viewedA->id, $viewedB->id] as $pid) {
            ProductInteraction::create([
                'user_id' => $user->id,
                'session_id' => 'sess',
                'product_id' => $pid,
                'interaction_type' => 'view',
                'interacted_at' => now(),
            ]);
        }
        ProductInteraction::create([
            'user_id' => $user->id,
            'session_id' => 'sess',
            'product_id' => $purchased->id,
            'interaction_type' => 'purchase',
            'interacted_at' => now(),
        ]);

        // Both viewed products recommend the SAME product (duplicate),
        // and A also recommends the already-purchased product.
        ProductRecommendation::create([
            'product_id' => $viewedA->id,
            'recommended_product_id' => $recommended->id,
            'score' => 0.9,
            'reason' => 'test',
        ]);
        ProductRecommendation::create([
            'product_id' => $viewedB->id,
            'recommended_product_id' => $recommended->id,
            'score' => 0.8,
            'reason' => 'test',
        ]);
        ProductRecommendation::create([
            'product_id' => $viewedA->id,
            'recommended_product_id' => $purchased->id,
            'score' => 0.95,
            'reason' => 'test',
        ]);

        $recs = $this->service->getPersonalizedRecommendations($user->id, null, 10);
        $ids = $recs->pluck('id');

        // Deduplicated: the recommended product appears exactly once.
        $this->assertEquals(1, $ids->filter(fn ($id) => $id === $recommended->id)->count());
        // Already-purchased product is excluded.
        $this->assertFalse($ids->contains($purchased->id));
        $this->assertTrue($ids->contains($recommended->id));
    }

    public function test_top_recommendations_scope_orders_by_score(): void
    {
        $product = $this->makeProduct();
        $prod1 = $this->makeProduct();
        $prod2 = $this->makeProduct();

        ProductRecommendation::create([
            'product_id' => $product->id,
            'recommended_product_id' => $prod1->id,
            'score' => 50.0,
            'reason' => 'test',
        ]);
        ProductRecommendation::create([
            'product_id' => $product->id,
            'recommended_product_id' => $prod2->id,
            'score' => 90.0,
            'reason' => 'test',
        ]);

        $top = ProductRecommendation::topRecommendations()->get();

        $this->assertEquals(90.0, $top->first()->score);
    }
}
