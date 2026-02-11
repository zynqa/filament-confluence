<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Zynqa\FilamentConfluence\Filament\Pages\ManageConfluenceSettings;
use Zynqa\FilamentConfluence\Filament\Resources\ConfluencePageResource;
use Zynqa\FilamentConfluence\Models\ConfluencePage;
use Zynqa\FilamentConfluence\Policies\ConfluencePagePolicy;

class FilamentConfluencePlugin implements Plugin
{
    use EvaluatesClosures;

    public function getId(): string
    {
        return 'filament-confluence';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                ConfluencePageResource::class,
            ]);
            // Note: ManageConfluenceSettings is NOT registered as a standalone page.
            // Instead, the package provides ConfluenceSettingsForm::getSchema()
            // which can be embedded as a tab in the app's existing settings page.
            // This approach keeps the navigation clean and provides better integration.
    }

    public function boot(Panel $panel): void
    {
        // Register policy
        \Illuminate\Support\Facades\Gate::policy(
            ConfluencePage::class,
            ConfluencePagePolicy::class
        );
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        return filament(app(static::class)->getId());
    }
}
