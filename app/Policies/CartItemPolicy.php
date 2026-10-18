<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesThroughTeam;

class CartItemPolicy
{
    use AuthorizesThroughTeam;

    protected function area(): string
    {
        return 'orders';
    }

    protected function platformSuffix(): string
    {
        return 'cart::item';
    }
}
