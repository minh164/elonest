<?php

namespace Minh164\EloNest;

use Illuminate\Support\ServiceProvider;
use Minh164\EloNest\Console\InspectingCommand;
use Minh164\EloNest\Console\RepairingCommand;

/**
 * Service provider for Elonest package.
 */
class ElonestServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InspectingCommand::class,
                RepairingCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }
}
