<?php

namespace Minh164\EloNest;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for Elonest package.
 */
class ElonestServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }
}
