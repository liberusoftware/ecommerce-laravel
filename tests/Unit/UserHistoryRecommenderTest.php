<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\User;
use App\Services\UserHistoryRecommender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserHistoryRecommenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_can_be_resolved(): void
    {
        $service = app(UserHistoryRecommender::class);

        $this->assertInstanceOf(UserHistoryRecommender::class, $service);
    }

    public function test_browsing_history_can_be_recorded_and_related(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $user->browsingHistory()->create(['product_id' => $product->id]);

        $this->assertDatabaseHas('browsing_histories', [
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);

        $history = $user->browsingHistory()->with('product')->first();
        $this->assertNotNull($history);
        $this->assertEquals($product->id, $history->product->id);
    }
}
