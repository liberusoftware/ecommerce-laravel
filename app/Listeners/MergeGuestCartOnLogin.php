<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\CartService;
use Illuminate\Auth\Events\Login;

class MergeGuestCartOnLogin
{
    public function __construct(private CartService $cartService) {}

    public function handle(Login $event): void
    {
        if ($event->user instanceof User) {
            $this->cartService->mergeGuestCartIntoAccount($event->user);
        }
    }
}
