<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesThroughTeam;

class CouponPolicy
{
    use AuthorizesThroughTeam;

    protected function area(): string
    {
        return 'catalogue';
    }

    protected function platformSuffix(): string
    {
        return 'coupon';
    }
}
