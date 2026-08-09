<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;

class RecommendationService
{
    public function getRecommendations(User $user, $limit = 5)
    {
        $purchasedProducts = $this->getPurchasedProducts($user);
        $browsedProducts = $this->getBrowsedProducts($user);
        $ratedProducts = $this->getRatedProducts($user);

        $recommendedProducts = collect();

        // Recommend based on purchase history
        $recommendedProducts = $recommendedProducts->merge($this->getRelatedProducts($purchasedProducts));

        // Recommend based on browsing history
        $recommendedProducts = $recommendedProducts->merge($this->getRelatedProducts($browsedProducts));

        // Recommend based on highly rated products
        $recommendedProducts = $recommendedProducts->merge($this->getHighlyRatedProducts($ratedProducts));

        // Remove duplicates and products already purchased or browsed.
        // NB: this is a base Collection of models, so exclude by id — a
        // Collection::diff() here would string-cast the models and fatal.
        $excludeIds = $purchasedProducts->pluck('id')
            ->merge($browsedProducts->pluck('id'))
            ->unique();

        return $recommendedProducts->unique('id')
            ->reject(fn ($product) => $excludeIds->contains($product->id))
            ->sortByDesc('rating')
            ->take($limit)
            ->values();
    }

    private function getPurchasedProducts(User $user)
    {
        // Order has no `products` relation; go through its line items.
        return $user->orders()->with('items.product')->get()
            ->pluck('items')->flatten()
            ->pluck('product')->filter()->unique('id');
    }

    private function getBrowsedProducts(User $user)
    {
        return $user->browsingHistory()->with('product')->orderBy('created_at', 'desc')->take(20)->get()->pluck('product');
    }

    private function getRatedProducts(User $user)
    {
        // Through the Customer since ADR 0008: ratings belong to the shopper,
        // and a user with no customer record has rated nothing.
        return $user->customer?->rating()->with('product')->get()->pluck('product') ?? collect();
    }

    private function getRelatedProducts($products)
    {
        // Product belongs to a single category (category_id), not a `categories`
        // relation. Recommend same-category products, excluding the seeds.
        $categoryIds = $products->pluck('category_id')->filter()->unique();

        if ($categoryIds->isEmpty()) {
            return collect();
        }

        return Product::whereIn('category_id', $categoryIds)
            ->whereNotIn('id', $products->pluck('id')->filter())
            ->get();
    }

    private function getHighlyRatedProducts($products)
    {
        // Product exposes `rating()` (ProductRating), which has a `rating` column.
        return Product::whereIn('id', $products->pluck('id'))
            ->withAvg('rating as rating', 'rating')
            ->orderByDesc('rating')
            ->take(10)
            ->get();
    }
}
