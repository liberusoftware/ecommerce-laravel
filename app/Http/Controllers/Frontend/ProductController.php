<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\BrowsingHistory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockNotification;
use App\Services\RecommendationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ProductController extends Controller
{
    protected $recommendationService;

    public function __construct(RecommendationService $recommendationService)
    {
        $this->recommendationService = $recommendationService;
    }

    /**
     * Register interest in an out-of-stock product. Guests supply an email;
     * authenticated users are linked by id. Duplicate pending subscriptions are
     * collapsed so a shopper is only emailed once per restock.
     */
    public function notifyMe(Request $request, Product $product)
    {
        $validated = $request->validate([
            'email' => 'required_without:user_id|nullable|email',
        ]);

        $email = $request->user()?->email ?? ($validated['email'] ?? null);

        if (! $email) {
            return response()->json(['success' => false, 'message' => 'An email address is required.'], 422);
        }

        StockNotification::firstOrCreate(
            [
                'product_id' => $product->id,
                'email' => $email,
                'notification_type' => 'back_in_stock',
                'notified' => false,
            ],
            ['user_id' => $request->user()?->id]
        );

        return response()->json([
            'success' => true,
            'message' => 'You will be notified when this product is back in stock.',
        ]);
    }

    public function index(Request $request)
    {
        $query = QueryBuilder::for(Product::class)
            ->allowedFilters(
                'name',
                'price',
                'created_at',
                AllowedFilter::scope('price_min'),
                AllowedFilter::scope('price_max'),
            )
            ->allowedSorts('name', 'price', 'created_at');

        if ($request->filled('keyword') || $request->filled('search')) {
            $keyword = $request->input('keyword') ?? $request->input('search');
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', '%'.$keyword.'%')
                    ->orWhere('description', 'like', '%'.$keyword.'%')
                    ->orWhere('short_description', 'like', '%'.$keyword.'%');
            });
        }

        $products = $query->paginate(config('pagination.per_page'))
            ->appends($request->query());

        return view('products.index', compact('products'));
    }

    public function search(Request $request)
    {
        $keyword = $request->input('keyword', '');
        $minPrice = $request->input('min_price');
        $maxPrice = $request->input('max_price');
        $categoryId = $request->input('category');

        $query = Product::query();

        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', '%'.$keyword.'%')
                    ->orWhere('description', 'like', '%'.$keyword.'%')
                    ->orWhere('short_description', 'like', '%'.$keyword.'%');
            });
        }

        if ($minPrice !== null) {
            $query->where('price', '>=', (float) $minPrice);
        }

        if ($maxPrice !== null) {
            $query->where('price', '<=', (float) $maxPrice);
        }

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->paginate(config('pagination.per_page'))->appends($request->query());

        $categories = ProductCategory::orderBy('name')->get();

        return view('products.search', compact('products', 'categories', 'keyword'));
    }

    public function show(Product $product)
    {
        // // Track browsing history
        // if (auth()->check()) {
        //     BrowsingHistory::create([
        //         'user_id' => auth()->id(),
        //         'product_id' => $product->id,
        //     ]);
        // }

        // // Get recommendations
        // $recommendations = [];
        // if (auth()->check()) {
        //     $recommendations = $this->recommendationService->getRecommendations(auth()->user());
        // }

        // $metaTitle = $product->meta_title ?? $product->name;
        // $metaDescription = $product->meta_description ?? $product->short_description;
        // $metaKeywords = $product->meta_keywords;
        // $canonicalUrl = route('products.show', ['category' => $category, 'product' => $product->slug]);

        return view('products.show', compact('product'));
    }
}
