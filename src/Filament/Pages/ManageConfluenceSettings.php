<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Filament\Pages;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\SettingsPage;
use Illuminate\Support\Facades\Cache;
use Zynqa\FilamentConfluence\Settings\ConfluenceSettings;

class ManageConfluenceSettings extends SettingsPage
{
    /**
     * This page is not shown in navigation.
     * Instead, use ConfluenceSettingsForm::getSchema() to embed
     * Confluence settings as a tab in your app's settings page.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Confluence';

    protected static string $settings = ConfluenceSettings::class;

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && $user->hasRole('super_admin');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Page Access Management')
                    ->description('Configure user access to Confluence pages on a per-user basis')
                    ->schema([
                        Forms\Components\Placeholder::make('access_info')
                            ->label('Granular Access Control')
                            ->content('Page access is now managed per-user. Navigate to Users > Edit User > Project & Account tab to configure Confluence space and page assignments.')
                            ->helperText('Users can be assigned entire spaces or specific pages with optional sub-page inclusion.')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),

                Forms\Components\Section::make('Connection Information')
                    ->description('Current Confluence connection details')
                    ->schema([
                        Forms\Components\Placeholder::make('connection_type')
                            ->label('Connection Type')
                            ->content(fn () => config('filament-confluence.connection') === 'mcp' ? 'MCP' : 'Direct API'),

                        Forms\Components\Placeholder::make('confluence_url')
                            ->label('Confluence URL')
                            ->content(fn () => config('filament-confluence.confluence_url'))
                            ->visible(fn () => config('filament-confluence.connection') === 'direct'),

                        Forms\Components\Placeholder::make('cloud_id')
                            ->label('Cloud ID')
                            ->content(fn () => config('filament-confluence.cloud_id'))
                            ->visible(fn () => config('filament-confluence.connection') === 'mcp'),

                        Forms\Components\Placeholder::make('content_format')
                            ->label('Content Format')
                            ->content(fn () => config('filament-confluence.content_format')),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Forms\Components\Section::make('Cache Management')
                    ->description('Clear Confluence caches to force fresh data from the API')
                    ->schema([
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('clear_all_cache')
                                ->label('Clear All Confluence Caches')
                                ->icon('heroicon-o-trash')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->action(function () {
                                    // Clear all Confluence-related caches
                                    Cache::forget('confluence_spaces');

                                    // Clear user-specific Sushi caches
                                    // This is a simplified version - in production you might want to track all user cache keys
                                    $user = auth()->user();
                                    if ($user) {
                                        Cache::forget('sushi:confluence_pages_user_'.$user->id);
                                    }

                                    \Filament\Notifications\Notification::make()
                                        ->title('Caches cleared successfully')
                                        ->success()
                                        ->send();
                                }),
                        ]),
                    ])
                    ->collapsible(),
            ]);
    }

    protected function afterSave(): void
    {
        // Clear caches after saving settings
        Cache::forget('confluence_spaces');

        \Filament\Notifications\Notification::make()
            ->title('Settings saved successfully')
            ->body('Confluence caches have been cleared. Users will see updated configuration on next page load.')
            ->success()
            ->send();
    }
}
