<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesThroughTeam;

class ProductReviewPolicy
{
    use AuthorizesThroughTeam;

    protected function area(): string
    {
        return 'catalogue';
    }

    protected function platformSuffix(): string
    {
        return 'product::review';
    }
}
