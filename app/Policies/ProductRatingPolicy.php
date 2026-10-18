<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesThroughTeam;

class ProductRatingPolicy
{
    use AuthorizesThroughTeam;

    protected function area(): string
    {
        return 'catalogue';
    }

    protected function platformSuffix(): string
    {
        return 'product::rating';
    }
}
