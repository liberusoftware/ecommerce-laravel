<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesThroughTeam;

/** A catalogue integration is store configuration. */
class FacebookConnectionPolicy
{
    use AuthorizesThroughTeam;

    protected function area(): string
    {
        return 'store';
    }

    protected function platformSuffix(): string
    {
        return 'facebook::connection';
    }
}
