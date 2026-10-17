<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel uploads store a public-disk path; the storefront needs a URL that loads
 * whatever page it appears on.
 */
class ProductImageUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_uploaded_image_path_resolves_to_the_public_storage_url(): void
    {
        $product = Product::factory()->make(['featured_image' => 'products/lamp.webp']);

        $this->assertSame('/storage/products/lamp.webp', $product->image_url);
    }

    public function test_external_and_root_relative_images_are_used_as_given(): void
    {
        $this->assertSame('https://cdn.example.com/a.jpg', Product::factory()->make(['featured_image' => 'https://cdn.example.com/a.jpg'])->image_url);
        $this->assertSame('/images/a.jpg', Product::factory()->make(['featured_image' => '/images/a.jpg'])->image_url);
    }

    public function test_a_product_without_an_image_has_no_url(): void
    {
        $this->assertNull(Product::factory()->make(['featured_image' => null])->image_url);
    }
}
