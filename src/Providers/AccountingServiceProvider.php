<?php

declare(strict_types=1);

namespace App\Accounting\Providers;

use Illuminate\Support\ServiceProvider;

class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/accounting.php', 'accounting');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../migrations/' => database_path('/migrations'),
        ], 'migrations');

        $this->publishes([
            __DIR__ . '/../../config/accounting.php' => config_path('accounting.php'),
        ], 'config');
    }
}
