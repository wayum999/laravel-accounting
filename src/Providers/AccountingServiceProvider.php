<?php

declare(strict_types=1);

namespace App\Accounting\Providers;

use Illuminate\Support\ServiceProvider;

class AccountingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Publish migrations
        $this->loadMigrationsFrom(__DIR__ . '/../migrations');

        // Publish config
        $this->publishes([
            __DIR__ . '/../../config/accounting.php' => config_path('accounting.php'),
        ], 'accounting-config');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/accounting.php',
            'accounting',
        );
    }
}
