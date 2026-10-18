<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesPlatformWide;

class TaxClassPolicy
{
    use AuthorizesPlatformWide;

    protected function platformSuffix(): string
    {
        return 'tax::class';
    }
}
