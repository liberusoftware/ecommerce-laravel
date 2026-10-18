<?php

namespace Tests\Unit;

use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCategoryMetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_category_persists_seo_meta(): void
    {
        $category = ProductCategory::create([
            'name' => 'Phones',
            'slug' => 'phones-'.uniqid(),
            'description' => 'd',
            'meta_title' => 'Phones | Store',
            'meta_description' => 'Buy phones',
        ]);

        $fresh = $category->fresh();
        $this->assertSame('Phones | Store', $fresh->meta_title);
        $this->assertSame('Buy phones', $fresh->meta_description);
    }
}
