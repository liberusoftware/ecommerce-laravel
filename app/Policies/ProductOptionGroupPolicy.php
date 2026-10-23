<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesThroughTeam;

/** Option groups are product configuration: the catalogue role and the platform product grants decide. */
class ProductOptionGroupPolicy
{
    use AuthorizesThroughTeam;

    protected function area(): string
    {
        return 'catalogue';
    }

    protected function platformSuffix(): string
    {
        return 'product';
    }
}
