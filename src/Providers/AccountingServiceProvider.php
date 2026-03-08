<?php

declare(strict_types=1);

namespace App\Accounting\Providers;

use App\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Relations\Relation;
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

        // Register a morph map for the library's own models so the 'account' alias
        // is stored instead of the full class name. This prevents namespace leakage
        // in polymorphic columns.
        //
        // Consumers should extend this map in their own AppServiceProvider to register
        // application-specific models. If you want strict enforcement (no unregistered
        // class names allowed), call Relation::enforceMorphMap() in your AppServiceProvider
        // after extending the map.
        Relation::morphMap([
            'account' => Account::class,
        ]);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/accounting.php',
            'accounting',
        );
    }
}
