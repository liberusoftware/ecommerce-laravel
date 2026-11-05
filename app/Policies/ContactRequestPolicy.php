<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesThroughTeam;

class ContactRequestPolicy
{
    use AuthorizesThroughTeam;

    protected function area(): string
    {
        return 'customers';
    }

    protected function platformSuffix(): string
    {
        return 'contact::request';
    }
}
