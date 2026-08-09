<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * The cart. One store, one door.
 *
 * There used to be two. A guest's cart lived in the session as a plain array;
 * a signed-in shopper's cart lived in `cart_items`, and this service mirrored
 * one into the other on login and on every write. The API and the GraphQL
 * mutation wrote `cart_items` directly and never saw the session at all.
 *
 * Two stores meant two carts that could disagree, and the web checkout charged
 * from the session copy — the one no other surface could read. A shopper who
 * added on their phone through the API and checked out on the web was charged
 * for a different cart than the one they filled.
 *
 * Now everything writes `cart_items`, and the session holds one thing: an
 * opaque token that says which rows are this visitor's. That is the shape
 * `CONFORMANCE.md` §D3 prescribes — *the session path becomes a guest
 * identifier on the same store.*
 *
 * `contents()` returns the array shape the storefront and checkout already
 * spoke, keyed by product id, so the callers changed where their cart comes
 * from and not what it looks like.
 */
class CartService
{
    /**
     * The session key holding the guest's claim on their rows.
     *
     * Not the session id itself: a session id is a credential, and this value
     * is written to a table that staff tooling and abandoned-cart jobs read.
     */
    private const GUEST_TOKEN = 'cart_token';

    /**
     * The cart as the storefront reads it — keyed by product id.
     *
     * `name`, `is_downloadable` and `weight` come from the product rather than
     * from the row, because they are the product's facts and a cart that
     * remembers a stale name is a cart that lies. `price` is the row's, because
     * that is what the shopper was shown when they added it.
     *
     * @return array<int, array{name: ?string, price: float, quantity: int, is_downloadable: bool, weight: float}>
     */
    public function contents(): array
    {
        $contents = [];

        foreach ($this->query()->with('products')->get() as $item) {
            $product = $item->products;

            $contents[$item->product_id] = [
                'name' => $product?->name,
                'price' => (float) $item->price,
                'quantity' => (int) $item->quantity,
                'is_downloadable' => (bool) $product?->is_downloadable,
                'weight' => (float) ($product?->weight ?? 0),
            ];
        }

        return $contents;
    }

    public function isEmpty(): bool
    {
        return ! $this->query()->exists();
    }

    public function subtotal(): float
    {
        return (float) $this->query()->get()->sum(fn (CartItem $item) => (float) $item->price * $item->quantity);
    }

    public function count(): int
    {
        return (int) $this->query()->sum('quantity');
    }

    public function has(int $productId): bool
    {
        return $this->query()->where('product_id', $productId)->exists();
    }

    /**
     * Add to the cart, or add to what is already there.
     *
     * The price is taken from the product now and kept, which is what the
     * session cart did: a price change between adding and checking out is not
     * something to spring on somebody at the payment step.
     */
    public function add(Product $product, int $quantity): void
    {
        $item = $this->query()->where('product_id', $product->id)->first();

        if ($item !== null) {
            $item->increment('quantity', $quantity);

            return;
        }

        CartItem::create($this->owner() + [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->price,
        ]);
    }

    public function setQuantity(int $productId, int $quantity): void
    {
        $this->query()->where('product_id', $productId)->update(['quantity' => $quantity]);
    }

    public function remove(int $productId): void
    {
        $this->query()->where('product_id', $productId)->delete();
    }

    public function clear(): void
    {
        $this->query()->delete();
    }

    /**
     * Fold the guest's cart into the account they just signed into.
     *
     * Quantities are combined rather than replaced — a shopper who added two
     * of something while signed out and one while signed in wanted three, and
     * either cart winning outright loses something they chose.
     *
     * The token is dropped afterwards, so the next guest on this browser starts
     * a cart of their own rather than inheriting somebody's.
     */
    public function mergeGuestCartIntoAccount(User $user): void
    {
        $token = Session::get(self::GUEST_TOKEN);

        if ($token === null) {
            return;
        }

        DB::transaction(function () use ($user, $token) {
            foreach (CartItem::where('guest_token', $token)->get() as $guestItem) {
                $existing = CartItem::where('user_id', $user->id)
                    ->where('product_id', $guestItem->product_id)
                    ->first();

                if ($existing !== null) {
                    $existing->increment('quantity', $guestItem->quantity);
                    $guestItem->delete();

                    continue;
                }

                $guestItem->forceFill(['user_id' => $user->id, 'guest_token' => null])->save();
            }
        });

        Session::forget(self::GUEST_TOKEN);
    }

    private function query(): Builder
    {
        return CartItem::query()->where($this->owner());
    }

    /**
     * Whose cart this is: the account when there is one, the guest token
     * otherwise. Exactly one, never both — a row that claimed both would be
     * reachable by a stranger holding the token after the owner signed in.
     *
     * @return array{user_id: int}|array{guest_token: string}
     */
    private function owner(): array
    {
        $user = Auth::user();

        return $user !== null
            ? ['user_id' => $user->id]
            : ['guest_token' => $this->guestToken()];
    }

    private function guestToken(): string
    {
        $token = Session::get(self::GUEST_TOKEN);

        if (! is_string($token) || $token === '') {
            $token = (string) Str::uuid();
            Session::put(self::GUEST_TOKEN, $token);
        }

        return $token;
    }
}
