<?php

namespace App\Livewire;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidProductConfigurationException;
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
    public function addToCart(int $productId, string $name = '', float $price = 0, int $quantity = 1, bool $isDownloadable = false, float $weight = 0, array $options = [], ?int $variantId = null): void
    {
        // The dispatched name, price, downloadable flag and weight are never
        // trusted: everything is derived from the product and the chosen options.
        $product = Product::active()->find($productId);
        if ($product === null) {
            session()->flash('error', 'This product is not available.');

            return;
        }

        try {
            $this->cart()->add($product, max(1, $quantity), $options, null, $variantId);
        } catch (InsufficientStockException) {
            session()->flash('error', 'Not enough inventory available.');

            return;
        } catch (InvalidProductConfigurationException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

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

    public function updateQuantity(int $lineId, int $quantity): void
    {
        if ($quantity < 1) {
            $this->addError('quantity', 'Quantity must be at least 1');

            return;
        }

        $line = $this->cart()->line($lineId);
        if ($line === null) {
            $this->addError('product', 'Product not found in cart');

            return;
        }

        try {
            $this->cart()->setQuantity($line, $quantity);
        } catch (InsufficientStockException) {
            session()->flash('error', 'Requested quantity exceeds available stock.');

            return;
        }

        $this->items = $this->cart()->contents();
        $this->dispatch('cartUpdated');
        session()->flash('success', 'Cart updated');
    }

    public function removeItem(int $lineId): void
    {
        if ($this->cart()->remove($lineId)) {
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
