<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesPlatformWide;

class MenuPolicy
{
    use AuthorizesPlatformWide;

    protected function platformSuffix(): string
    {
        return 'menu';
    }
}
