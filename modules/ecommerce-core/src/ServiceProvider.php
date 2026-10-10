<?php

namespace Liberu\Ecommerce\Core;

use Illuminate\Support\ServiceProvider;

class ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
        $this->loadFactoriesFrom(__DIR__ . '/database/factories');
    }
}
