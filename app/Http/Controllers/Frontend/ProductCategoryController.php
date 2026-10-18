<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ProductCategoryController extends Controller
{
    public function index()
    {
        $categories = QueryBuilder::for(ProductCategory::class)
            ->allowedFilters('name')
            ->allowedSorts('name', 'created_at')
            ->topLevel()
            ->withCount(['products' => fn ($q) => $q->listed()])
            ->with(['children' => fn ($query) => $query->withCount(['products' => fn ($q) => $q->listed()])->orderBy('name')])
            ->defaultSort('name')
            ->paginate();

        return view('categories.index', compact('categories'));
    }

    public function show(ProductCategory $category)
    {
        $categoryIds = $category->listingCategoryIds();

        $productCount = Product::listed()
            ->whereIn('category_id', $categoryIds)
            ->count();

        $featuredProducts = Product::listed()
            ->whereIn('category_id', $categoryIds)
            ->withCount('review')
            ->withAvg('rating', 'rating')
            ->latest()
            ->take(4)
            ->get();

        $subcategories = $category->children()->withCount(['products' => fn ($q) => $q->listed()])->orderBy('name')->get();

        return view('categories.show', compact('category', 'productCount', 'featuredProducts', 'subcategories'));
    }

    public function products(Request $request, ProductCategory $category)
    {
        $products = QueryBuilder::for(Product::class)
            ->allowedFilters(
                'name',
                'price',
                'created_at',
                AllowedFilter::scope('price_min'),
                AllowedFilter::scope('price_max'),
            )
            ->whereIn('category_id', $category->listingCategoryIds())
            ->listed()
            ->withCount('review')
            ->withAvg('rating', 'rating')
            ->allowedSorts('name', 'price', 'created_at')
            ->paginate(config('pagination.per_page'))
            ->appends($request->query());

        return view('categories.products', compact('category', 'products'));
    }
}
