<?php

namespace App\Livewire;

use App\Services\CartService;
use Livewire\Attributes\On;
use Livewire\Component;

class CartCount extends Component
{
    #[On('cartUpdated')]
    public function refresh(): void {}

    public function render()
    {
        return view('livewire.cart-count', [
            // Counted in the database rather than summed out of a session
            // array: guests and accounts now keep their cart in the same place,
            // and this badge was the last thing reading the old copy.
            'count' => app(CartService::class)->count(),
        ]);
    }
}
