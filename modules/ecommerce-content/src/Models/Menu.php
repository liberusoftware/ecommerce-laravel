<?php

namespace Liberu\Ecommerce\Content\Models;

use Liberu\Ecommerce\Content\Traits\IsTenantModel;
use Biostate\FilamentMenuBuilder\Models\Menu as BaseMenu;

class Menu extends BaseMenu
{
    use IsTenantModel;
}