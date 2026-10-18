<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesPlatformWide;

class PagePolicy
{
    use AuthorizesPlatformWide;

    protected function platformSuffix(): string
    {
        return 'page';
    }
}
