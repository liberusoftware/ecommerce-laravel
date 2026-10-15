<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomePageQueryTest extends TestCase
{
    use RefreshDatabase;

    /** Unrated products have a null eager-loaded average; that must not trigger a query each. */
    public function test_product_cards_do_not_query_ratings_one_product_at_a_time(): void
    {
        Product::factory()->count(6)->create(['is_featured' => true]);
        Product::factory()->count(6)->create(['is_featured' => false]);

        DB::enableQueryLog();
        $this->get('/')->assertOk();

        $perProduct = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], 'avg("rating") as "aggregate"'))
            ->count();

        $this->assertSame(0, $perProduct);
    }
}
