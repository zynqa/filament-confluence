<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Zynqa\FilamentConfluence\Services\ConfluenceService;

class FilamentConfluenceServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('filament-confluence')
            ->hasConfigFile()
            ->hasTranslations()
            ->hasMigration('add_confluence_fields_to_users_table');
    }

    public function packageBooted(): void
    {
        // Register singleton service
        $this->app->singleton(ConfluenceService::class, function ($app) {
            return new ConfluenceService;
        });
    }
}
