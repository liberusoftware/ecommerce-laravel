<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesPlatformWide;

class MenuItemPolicy
{
    use AuthorizesPlatformWide;

    protected function platformSuffix(): string
    {
        return 'menu::item';
    }
}
