<?php

namespace App\Livewire;

use App\Models\Product;
use App\Services\CartService;
use Livewire\Attributes\On;
use Livewire\Component;

class ShoppingCart extends Component
{
    public array $items = [];

    public function mount(): void
    {
        $this->items = $this->cart()->contents();
    }

    /**
     * The one door onto the cart store. Resolved per call rather than held as a
     * property: a Livewire component is serialised between requests, and a
     * service that survives that round trip is a service holding a stale
     * identity for whoever comes back.
     */
    private function cart(): CartService
    {
        return app(CartService::class);
    }

    public function render()
    {
        return view('livewire.shopping-cart', [
            'items' => $this->items,
            'total' => $this->calculateTotal(),
            'hasPhysicalProducts' => $this->hasPhysicalProducts(),
            'canCheckout' => count($this->items) > 0,
        ]);
    }

    #[On('addToCart')]
    public function addToCart(int $productId, string $name = '', float $price = 0, int $quantity = 1, bool $isDownloadable = false, float $weight = 0): void
    {
        // Never trust the dispatched name/price/isDownloadable/weight — the checkout
        // charges whatever the session cart holds, so a client could set price=0.01,
        // forge the downloadable flag to skip the stock gate, or send a negative
        // quantity. Derive everything from the Product and clamp the quantity.
        $product = Product::findOrFail($productId);
        $quantity = max(1, $quantity);
        $isDownloadable = (bool) $product->is_downloadable;

        if (! $isDownloadable && $product->inventory_count < $quantity) {
            session()->flash('error', 'Not enough inventory available.');

            return;
        }

        if (isset($this->items[$productId])) {
            $newQuantity = $this->items[$productId]['quantity'] + $quantity;
            if (! $isDownloadable && $newQuantity > $product->inventory_count) {
                session()->flash('error', 'Cannot add more items than available in stock.');

                return;
            }
        }

        $this->cart()->add($product, $quantity);
        $this->items = $this->cart()->contents();
        $this->dispatch('cartUpdated');
        session()->flash('success', 'Product added to cart successfully!');
    }

    public function hasPhysicalProducts(): bool
    {
        foreach ($this->items as $item) {
            if (! $item['is_downloadable']) {
                return true;
            }
        }

        return false;
    }

    public function updateQuantity(int $productId, int $quantity): void
    {
        if ($quantity < 1) {
            $this->addError('quantity', 'Quantity must be at least 1');

            return;
        }

        if (! isset($this->items[$productId])) {
            $this->addError('product', 'Product not found in cart');

            return;
        }

        $isDownloadable = $this->items[$productId]['is_downloadable'] ?? false;
        if (! $isDownloadable) {
            $product = Product::find($productId);
            if (! $product) {
                $this->addError('product', 'Product not found');

                return;
            }
            if ($quantity > $product->inventory_count) {
                session()->flash('error', 'Requested quantity exceeds available stock.');

                return;
            }
        }

        $this->cart()->setQuantity($productId, $quantity);
        $this->items = $this->cart()->contents();
        $this->dispatch('cartUpdated');
        session()->flash('success', 'Cart updated');
    }

    public function removeItem(int $productId): void
    {
        if (isset($this->items[$productId])) {
            $this->cart()->remove($productId);
            $this->items = $this->cart()->contents();
            $this->dispatch('cartUpdated');
            session()->flash('success', 'Item removed from cart');
        }
    }

    public function clearCart(): void
    {
        $this->cart()->clear();
        $this->items = [];
        $this->dispatch('cartUpdated');
        session()->flash('success', 'Cart cleared');
    }

    public function calculateTotal(): float
    {
        return round(array_reduce(
            $this->items,
            fn (float $carry, array $item) => $carry + ($item['price'] * $item['quantity']),
            0.0
        ), 2);
    }
}
