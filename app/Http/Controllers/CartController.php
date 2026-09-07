<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\CartService;
use App\Services\CouponService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

/**
 * The storefront cart. Backed by `cart_items` for guests and accounts alike —
 * see {@see CartService}, which is the only door onto that store.
 *
 * This controller used to keep the cart in the session and mirror it into
 * `cart_items` for signed-in shoppers on every write. The mirror is gone: there
 * is one cart, and the API, the GraphQL mutation and this controller all read
 * and write it.
 */
class CartController extends Controller
{
    public function __construct(private CartService $cart) {}

    public function add(Request $request, Product $product)
    {
        // Guard the quantity — a negative value passes the `inventory_count < $quantity`
        // check and, at checkout, drags the total down and INCREMENTS stock via the
        // atomic decrement. (add() previously skipped the min:1 guard that update() has.)
        $request->validate(['quantity' => 'nullable|integer|min:1']);
        $quantity = (int) $request->input('quantity', 1);

        if ($product->inventory_count < $quantity) {
            return redirect()->back()->with('error', 'Not enough inventory available.');
        }

        $this->cart->add($product, $quantity);

        return redirect()->back()->with('success', 'Product added to cart successfully!');
    }

    public function index()
    {
        return view('cart.index');
    }

    public function update(Request $request, $productId)
    {
        $quantity = $request->input('quantity', 1);

        if ($quantity < 1) {
            return redirect()->back()->with('error', 'Quantity must be at least 1.');
        }

        if (! $this->cart->has((int) $productId)) {
            return redirect()->back()->with('error', 'Product not found in cart.');
        }

        $product = Product::find($productId);
        if (! $product) {
            return redirect()->back()->with('error', 'Product not found.');
        }

        if ($product->inventory_count < $quantity) {
            return redirect()->back()->with('error', 'Not enough inventory available.');
        }

        $this->cart->setQuantity((int) $productId, (int) $quantity);

        return redirect()->back()->with('success', 'Cart updated successfully!');
    }

    public function remove($productId)
    {
        if (! $this->cart->has((int) $productId)) {
            return redirect()->back()->with('error', 'Product not found in cart.');
        }

        $this->cart->remove((int) $productId);

        return redirect()->back()->with('success', 'Product removed from cart successfully!');
    }

    public function clear()
    {
        $this->cart->clear();
        Session::forget('coupon');

        return redirect()->back()->with('success', 'Cart cleared successfully!');
    }

    public function applyCoupon(Request $request, CouponService $couponService)
    {
        $request->validate([
            'coupon_code' => 'required|string',
        ]);

        if ($this->cart->isEmpty()) {
            return redirect()->back()->with('error', 'Your cart is empty.');
        }

        $result = $couponService->validateAndApplyCoupon($request->coupon_code, $this->cart->subtotal());

        if ($result['valid']) {
            Session::put('coupon', [
                'code' => $request->coupon_code,
                'discount' => $result['discount'],
                'coupon_id' => $result['coupon']->id,
            ]);

            return redirect()->back()->with('success', $result['message']);
        }

        return redirect()->back()->with('error', $result['error']);
    }

    public function removeCoupon()
    {
        Session::forget('coupon');

        return redirect()->back()->with('success', 'Coupon removed successfully!');
    }
}
