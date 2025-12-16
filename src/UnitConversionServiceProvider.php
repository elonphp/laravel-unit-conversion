<?php

namespace Elonphp\UnitConversion;

use Elonphp\UnitConversion\Console\SeedUnitsCommand;
use Elonphp\UnitConversion\Services\UnitConverter;
use Illuminate\Support\ServiceProvider;

class UnitConversionServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/unit-conversion.php',
            'unit-conversion'
        );

        $this->app->singleton('unit-converter', function ($app) {
            return new UnitConverter();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/unit-conversion.php' => config_path('unit-conversion.php'),
        ], 'unit-conversion-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'unit-conversion-migrations');

        // Load migrations only if not published to project
        if (!$this->migrationsPublished()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                SeedUnitsCommand::class,
            ]);

            // Publish default unit data
            $this->publishes([
                __DIR__ . '/../database/data/' => database_path('data/unit-conversion'),
            ], 'unit-conversion-data');
        }
    }

    /**
     * Check if migrations have been published to the project.
     */
    protected function migrationsPublished(): bool
    {
        return count(glob(database_path('migrations/*_create_cfg_units_table.php'))) > 0;
    }
}
