<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidProductConfigurationException;
use App\Models\CartItem;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
 * A line is a product plus its variant and the choices made for it (its
 * configuration key), so a black and a white T-shirt, or the same phone with
 * and without a case, are separate lines. Every door — web,
 * Livewire, REST and GraphQL — adds through here, so identity, pricing and
 * the stock rule are decided once.
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

    private ?User $user = null;

    public function __construct(private ProductOptionResolver $resolver) {}

    /** The cart of a user authenticated by some other guard than the session (a Sanctum token). */
    public function for(User $user): static
    {
        $cart = clone $this;
        $cart->user = $user;

        return $cart;
    }

    /**
     * The cart as the storefront reads it, keyed by cart line id.
     *
     * `name`, `is_downloadable` and `weight` come from the product rather than
     * from the row, because they are the product's facts. `price` and `options`
     * are the row's: what the shopper was shown when they added it.
     *
     * @return array<int, array{product_id: int, name: ?string, options: array, price: float, quantity: int, is_downloadable: bool, weight: float}>
     */
    public function contents(): array
    {
        $contents = [];

        foreach ($this->query()->with(['product.store', 'variant'])->orderBy('id')->get() as $item) {
            $product = $item->product;

            $contents[$item->id] = [
                'product_id' => (int) $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'name' => $product?->name,
                'variant' => OrderItem::variantLabelFor($item->variant?->attribute_values),
                'sku' => $item->variant?->sku ?? $product?->sku,
                'options' => $item->options ?? [],
                'price' => (float) $item->price,
                'quantity' => (int) $item->quantity,
                'is_downloadable' => (bool) $product?->is_downloadable,
                'weight' => (float) ($product?->weight ?? 0),
                'image' => $product?->image_url,
                'url' => $product ? route('products.show', $product) : null,
                'currency' => $product?->store?->currency_code ?? Money::currency(),
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

    /** Every line drawing on the same stock counts against it, whatever options were chosen. */
    public function quantityHeldBy(Product|ProductVariant $holder): int
    {
        return (int) $this->query()
            ->when($holder instanceof ProductVariant,
                fn ($q) => $q->where('product_variant_id', $holder->getKey()),
                fn ($q) => $q->where('product_id', $holder->getKey())->whereNull('product_variant_id'))
            ->sum('quantity');
    }

    public function line(int $lineId): ?CartItem
    {
        return $this->query()->with(['product', 'variant'])->find($lineId);
    }

    /**
     * Add to the cart, or to the line with the same variant and choices. The
     * line takes the current price; checkout refuses a line whose price has
     * since moved. `$unitPrice` is a pay-what-you-want amount the caller has
     * validated.
     *
     * @param  array<int|string>  $choiceIds
     *
     * @throws InvalidProductConfigurationException
     * @throws InsufficientStockException
     */
    public function add(Product $product, int $quantity, array $choiceIds = [], ?float $unitPrice = null, int|string|null $variantId = null): CartItem
    {
        $configuration = $this->resolver->resolve($product, $choiceIds, $variantId);
        $holder = $configuration->variant ?? $product;

        if (! $holder->canSupply($this->quantityHeldBy($holder) + $quantity)) {
            throw InsufficientStockException::make();
        }

        $price = $unitPrice ?? $configuration->unitPrice;
        $item = $this->query()
            ->where('product_id', $product->id)
            ->where('configuration_key', $configuration->key)
            ->first();

        if ($item !== null) {
            $item->increment('quantity', $quantity, ['price' => $price, 'options' => $configuration->options]);

            return $item->refresh();
        }

        return CartItem::create($this->owner() + [
            'product_id' => $product->id,
            'product_variant_id' => $configuration->variant?->id,
            'configuration_key' => $configuration->key,
            'options' => $configuration->options,
            'quantity' => $quantity,
            'price' => $price,
        ]);
    }

    /** @return Collection<int, CartItem> */
    public function items(): Collection
    {
        return $this->query()->with(['product', 'variant'])->orderBy('id')->get();
    }

    /** @throws InsufficientStockException */
    public function setQuantity(CartItem $line, int $quantity): CartItem
    {
        $holder = $line->product_variant_id !== null ? $line->variant : $line->product;

        if ($holder !== null && ! $holder->canSupply($this->quantityHeldBy($holder) - $line->quantity + $quantity)) {
            throw InsufficientStockException::make();
        }

        $line->update(['quantity' => $quantity]);

        return $line;
    }

    public function remove(int $lineId): bool
    {
        return $this->query()->whereKey($lineId)->delete() > 0;
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
     * either cart winning outright loses something they chose. Only lines with
     * the same choices combine.
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
                    ->where('configuration_key', $guestItem->configuration_key)
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
        $user = $this->user ?? Auth::user();

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
