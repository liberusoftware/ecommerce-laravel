<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Support\Str;

class WishlistController extends Controller
{
    public function index()
    {
        $wishlist = auth()->user()->wishlist()->with(['product.variants', 'product.optionGroups'])->get();

        return view('wishlist.index', compact('wishlist'));
    }

    public function add(Product $product)
    {
        $wishlist = Wishlist::firstOrCreate([
            'user_id' => auth()->id(),
            'product_id' => $product->id,
        ]);

        return redirect()->back()->with('success', 'Product added to wishlist');
    }

    public function remove(Product $product)
    {
        auth()->user()->wishlist()->where('product_id', $product->id)->delete();

        return redirect()->back()->with('success', 'Product removed from wishlist');
    }

    public function share()
    {
        // One token per user — the shared link exposes that user's whole wishlist.
        $user = auth()->user();
        $user->wishlist_share_token = Str::random(32);
        $user->save();

        return redirect()->route('wishlist.index')
            ->with('share_url', route('wishlist.shared', $user->wishlist_share_token));
    }

    public function sharedWishlist($shareToken)
    {
        $owner = User::where('wishlist_share_token', $shareToken)->first();
        $wishlist = $owner ? $owner->wishlist()->with('product')->get() : collect();

        return view('wishlist.shared', compact('wishlist'));
    }
}
