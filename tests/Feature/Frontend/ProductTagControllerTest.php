<?php

namespace Tests\Feature\Frontend;

use App\Models\Product;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTagControllerTest extends TestCase
{
    use RefreshDatabase;

    // Test that no tags exist
    public function test_list_tags_when_none_exist()
    {
        $response = $this->get(route('tags.index'));

        $response->assertStatus(200);
        $response->assertViewIs('tags.index');
        $response->assertViewHas('tags', function ($tags) {
            return $tags->isEmpty();
        });
    }

    // Test that exactly one tag exists
    public function test_list_tags_with_one_tag()
    {
        $tagName = 'Test Tag';
        $tag = Tag::factory()->create([
            'name' => $tagName,
        ]);
        // ProductTag::factory()->create(["tag_id" => $tag->id]);

        $response = $this->get(route('tags.index'));

        $response->assertStatus(200);
        $response->assertViewIs('tags.index');
        $response->assertViewHas('tags', function ($tags) use ($tag, $tagName) {
            return
                $tags->count() === 1
                && $tags->first()->id === $tag->id
                && $tags->first()->name === $tagName;
        });
    }

    // Test that multiple tags exist
    public function test_list_tags_with_multiple_tags()
    {
        $tags = Tag::factory()->count(3)->sequence(
            ['name' => 'Tag 1'],
            ['name' => 'Tag 2'],
            ['name' => 'Tag 3']
        )->create();
        // ProductTag::factory()->count(3)->sequence(
        //     ["tag_id" => $tags->get(0)],
        //     ["tag_id" => $tags->get(1)],
        //     ["tag_id" => $tags->get(2)],
        // )->create();

        $response = $this->get(route('tags.index'));

        $response->assertStatus(200);
        $response->assertViewIs('tags.index');
        $response->assertViewHas('tags', function ($viewTags) use ($tags) {
            if ($viewTags->count() !== 3) {
                return false;
            }

            $viewTagNames = $viewTags->pluck('name');
            $tagNames = $tags->pluck('name');

            return $tagNames->every(fn ($name) => $viewTagNames->contains($name));
        });
    }

    // Test that searching returns no results for a non-existent tag
    public function test_search_returns_no_results_for_non_existent_tag()
    {
        Tag::factory()->count(3)->sequence(
            ['name' => 'Tag 1'],
            ['name' => 'Tag 2'],
            ['name' => 'Tag 3']
        )->create();
        // ProductTag::factory()->count(3)->sequence(
        //     ["tag_id" => $tags->get(0)],
        //     ["tag_id" => $tags->get(1)],
        //     ["tag_id" => $tags->get(2)],
        // )->create();

        $response = $this->get(route('tags.index', ['filter[name]' => 'NonExistentTag']));

        $response->assertStatus(200);
        $response->assertViewIs('tags.index');
        $response->assertViewHas('tags', function ($tags) {
            return $tags->isEmpty();
        });
    }

    // Test that searching returns a single result for an exact match
    public function test_search_returns_matching_tag()
    {
        $tagName = 'TagMatch';
        Tag::factory()->count(3)->sequence(
            ['name' => 'Tag 1'],
            ['name' => 'Tag 2'],
            ['name' => $tagName]
        )->create();
        // ProductTag::factory()->count(3)->sequence(
        //     ["tag_id" => $tags->get(0)],
        //     ["tag_id" => $tags->get(1)],
        //     ["tag_id" => $tags->get(2)],
        // )->create();

        $response = $this->get(route('tags.index', ['filter[name]' => $tagName]));

        $response->assertStatus(200);
        $response->assertViewIs('tags.index');
        $response->assertViewHas('tags', function ($tags) use ($tagName) {
            return $tags->count() === 1
                && $tags->first()->name === $tagName;
        });
    }

    // Test that tags are sorted in ascending order
    public function test_tags_are_sorted_in_ascending_order()
    {
        Tag::factory()->count(3)->sequence(
            ['name' => 'Tag 3'],
            ['name' => 'Tag 1'],
            ['name' => 'Tag 2']
        )->create();
        // ProductTag::factory()->count(3)->sequence(
        //     ["tag_id" => $tags->get(0)],
        //     ["tag_id" => $tags->get(1)],
        //     ["tag_id" => $tags->get(2)],
        // )->create();

        $response = $this->get(route('tags.index', ['sort' => 'name']));

        $response->assertStatus(200);
        $response->assertViewIs('tags.index');
        $response->assertViewHas('tags', function ($viewTags) {
            return $viewTags->pluck('name')->toArray() === ['Tag 1', 'Tag 2', 'Tag 3'];
        });
    }

    // Test that tags are sorted in descending order
    public function test_tags_are_sorted_in_descending_order()
    {
        Tag::factory()->count(3)->sequence(
            ['name' => 'Tag 3'],
            ['name' => 'Tag 1'],
            ['name' => 'Tag 2']
        )->create();
        // ProductTag::factory()->count(3)->sequence(
        //     ["tag_id" => $tags->get(0)],
        //     ["tag_id" => $tags->get(1)],
        //     ["tag_id" => $tags->get(2)],
        // )->create();

        $response = $this->get(route('tags.index', ['sort' => '-name']));

        $response->assertStatus(200);
        $response->assertViewIs('tags.index');
        $response->assertViewHas('tags', function ($viewTags) {
            return $viewTags->pluck('name')->toArray() === ['Tag 3', 'Tag 2', 'Tag 1'];
        });
    }

    // Test retrieving a tag returns 404 for non-existing tag
    public function test_retrieve_tag_returns_404_for_non_existing()
    {
        $response = $this->get(route('tags.show', ['tag' => 999]));
        $response->assertStatus(404);
    }

    // Test retrieving a tag with products
    public function test_can_retrieve_tag_with_its_products()
    {
        $tag = Tag::factory()->create();
        $product = Product::factory()->create();
        $product->tags()->attach($tag);

        $response = $this->get(route('tags.show', $tag->id));

        $response->assertStatus(200);
        $response->assertViewHas('tag', function ($viewTag) use ($tag, $product) {
            return $viewTag->id === $tag->id
                && $viewTag->products->contains($product);
        });
    }

    // Test tag products page shows only products belonging to the specific tag
    public function test_tag_shows_only_its_own_products()
    {
        $tag1 = Tag::factory()->create();
        $tag2 = Tag::factory()->create();

        $products = Product::factory()->count(5)->create();
        $products->get(0)->tags()->attach($tag1);
        $products->get(1)->tags()->attach($tag2);
        $products->get(2)->tags()->attach($tag1);
        $products->get(3)->tags()->attach($tag2);
        $products->get(4)->tags()->attach($tag2);

        $response = $this->get(route('tags.show', ['tag' => $tag1]));

        $response->assertStatus(200);
        $response->assertViewIs('tags.show');
        $response->assertViewHas('products', function ($viewProducts) use ($products) {
            $tag1Products = collect([$products->get(0), $products->get(2)]);

            return
                $viewProducts->count() === 2 &&
                $viewProducts->pluck('id')->diff($tag1Products->pluck('id'))->isEmpty();
        });
    }

    // Test fetching products for a given tag with pagination
    public function test_can_view_paginated_products_for_tag()
    {
        $tag = Tag::factory()->create();
        Product::factory()->count(5)->create()->each(function ($product) use ($tag) {
            $product->tags()->attach($tag);
        });
        config(['pagination.per_page' => 3]);

        $response = $this->get(route('tags.show', ['tag' => $tag, 'page' => 2]));

        $response->assertStatus(200);
        $response->assertViewIs('tags.show');
        $response->assertViewHas('products', function ($viewProducts) {
            return $viewProducts->count() === 2;
        });
    }
}
