<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence;

use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Zynqa\FilamentConfluence\Services\ConfluenceContentTransformer;
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
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Register singleton service
        $this->app->singleton(ConfluenceService::class, function ($app) {
            return new ConfluenceService;
        });

        $this->app->singleton(ConfluenceContentTransformer::class, function ($app) {
            return new ConfluenceContentTransformer;
        });

        // Styles the Info, Note, Warning and Success callouts in Confluence page content.
        FilamentAsset::register([
            Css::make('filament-confluence', __DIR__.'/../resources/dist/filament-confluence.css'),
        ], package: 'zynqa/filament-confluence');
    }
}
