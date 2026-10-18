<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesThroughTeam;

/** Shipping methods are store configuration. */
class ShippingMethodPolicy
{
    use AuthorizesThroughTeam;

    protected function area(): string
    {
        return 'store';
    }

    protected function platformSuffix(): string
    {
        return 'shipping::method';
    }
}
