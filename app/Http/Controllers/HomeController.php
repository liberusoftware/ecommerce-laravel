<?php

/**
 * HomeController handles the requests for the home page of the ecommerce platform.
 * It passes the category index and the featured/latest product rails to the home view.
 */

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * Displays the home page: the category index, featured products, and latest arrivals.
     *
     * @return View The home view populated with real catalogue data.
     */
    public function index(): View
    {
        // Top-level categories, each counting its subcategories' products too. An
        // empty category is a dead end, so it is never offered.
        $categories = ProductCategory::query()
            ->topLevel()
            ->withCount(['products' => fn ($q) => $q->listed()])
            ->with(['children' => fn ($query) => $query->withCount(['products' => fn ($q) => $q->listed()])])
            ->orderBy('name')
            ->get()
            ->each(fn (ProductCategory $category) => $category->products_count += $category->children->sum('products_count'))
            ->filter(fn (ProductCategory $category) => $category->products_count > 0)
            ->values();

        // withCount/withAvg keep the card's rating block from firing two queries
        // per product across the grid.
        $withRatings = fn ($query) => $query->with(['variants', 'optionGroups'])->withCount('review')->withAvg('rating', 'rating');

        return view('home', [
            'categories' => $categories,
            'featuredProducts' => $withRatings(Product::listed()->where('is_featured', true))->take(6)->get(),
            'latestProducts' => $withRatings(Product::listed()->latest())->take(6)->get(),
        ]);
    }
}
