<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ProductTagController extends Controller
{
    public function index()
    {
        $tags = QueryBuilder::for(Tag::class)
            ->allowedFilters('name')
            ->allowedSorts('name', 'created_at')
            ->withCount('products')
            ->paginate();

        return view('tags.index', compact('tags'));
    }

    public function show(Request $request, Tag $tag)
    {
        $products = QueryBuilder::for(Product::class)
            ->allowedFilters(
                'name',
                'price',
                'created_at',
                AllowedFilter::scope('price_min'),
                AllowedFilter::scope('price_max'),
            )
            ->withTag($tag)
            ->listed()
            ->allowedSorts('name', 'price', 'created_at')
            ->withCount('review')
            ->withAvg('rating', 'rating')
            ->paginate(config('pagination.per_page'))
            ->appends($request->query());

        return view('tags.show', compact('tag', 'products'));
    }
}
