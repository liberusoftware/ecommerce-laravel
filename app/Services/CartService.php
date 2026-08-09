<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

/**
 * Bridges the session cart (the live path used by checkout) with a persistent
 * cart_items store so a signed-in shopper's cart survives logout / another device.
 */
class CartService
{
    /**
     * Replace the user's stored cart with the current session cart. Guests have
     * no user_id, so nothing is stored for them.
     */
    public function persistForUser(User $user, array $sessionCart): void
    {
        DB::transaction(function () use ($user, $sessionCart) {
            CartItem::where('user_id', $user->id)->delete();

            foreach ($sessionCart as $productId => $line) {
                CartItem::create([
                    'user_id' => $user->id,
                    'product_id' => $productId,
                    'quantity' => $line['quantity'],
                    'price' => $line['price'],
                ]);
            }
        });
    }

    /**
     * On login, fold the user's stored cart into their current (guest) session
     * cart — quantities for the same product are combined — then re-persist the
     * merged result so it stays saved.
     */
    public function mergeIntoSession(User $user): void
    {
        $cart = Session::get('cart', []);

        foreach (CartItem::where('user_id', $user->id)->with('products')->get() as $item) {
            $productId = $item->product_id;

            if (isset($cart[$productId])) {
                $cart[$productId]['quantity'] += $item->quantity;
            } else {
                $product = $item->products;
                $cart[$productId] = [
                    'name' => $product?->name,
                    'price' => (float) $item->price,
                    'quantity' => $item->quantity,
                    'is_downloadable' => (bool) ($product?->is_downloadable),
                ];
            }
        }

        Session::put('cart', $cart);
        $this->persistForUser($user, $cart);
    }
}
