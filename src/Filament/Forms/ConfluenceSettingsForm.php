<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Filament\Forms;

use Filament\Forms;
use Illuminate\Support\Facades\Cache;

/**
 * Provides Confluence settings form components that can be embedded
 * in any Filament settings page (e.g., General Settings).
 *
 * This allows apps to integrate Confluence settings as a tab
 * in their existing settings pages without creating a separate page.
 */
class ConfluenceSettingsForm
{
    /**
     * Get the complete schema for Confluence settings tab
     * Use this to embed Confluence settings in your app's settings page
     */
    public static function getSchema(): array
    {
        return [
            Forms\Components\Section::make('Page Access Management')
                ->description('Configure user access to Confluence pages on a per-user basis')
                ->collapsible()
                ->schema([
                    Forms\Components\Placeholder::make('access_info')
                        ->label('Granular Access Control')
                        ->content('Page access is now managed per-user. Navigate to Users > Edit User > Project & Account tab to configure Confluence space and page assignments.')
                        ->helperText('Users can be assigned entire spaces or specific pages with optional sub-page inclusion.')
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Connection Information')
                ->description('Current Confluence connection details')
                ->collapsible()
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
                ->columns(2),

            Forms\Components\Section::make('Cache Management')
                ->description('Clear Confluence caches to force fresh data from the API')
                ->collapsible()
                ->schema([
                    Forms\Components\Placeholder::make('cache_info')
                        ->label('Cache Status')
                        ->content(function () {
                            $sushiFile = storage_path('framework/cache/sushi-zynqa-filament-confluence-models-confluence-page.sqlite');
                            $cacheExists = file_exists($sushiFile);

                            if (! $cacheExists) {
                                return '❌ No cache file found';
                            }

                            $lastModified = \Carbon\Carbon::createFromTimestamp(filemtime($sushiFile));

                            return '✅ Cache exists (Last modified: '.$lastModified->diffForHumans().')';
                        })
                        ->columnSpanFull(),

                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('clear_my_cache')
                            ->label('Clear My Cache')
                            ->icon('heroicon-o-trash')
                            ->color('warning')
                            ->requiresConfirmation()
                            ->modalHeading('Clear My Confluence Cache')
                            ->modalDescription('This will clear your cached Confluence pages and fetch fresh data on your next visit to the Knowledge section.')
                            ->action(function () {
                                static::clearUserCache();
                            }),

                        Forms\Components\Actions\Action::make('clear_all_cache')
                            ->label('Clear All Users Cache')
                            ->icon('heroicon-o-trash')
                            ->color('danger')
                            ->visible(fn () => auth()->user()?->hasRole('super_admin'))
                            ->requiresConfirmation()
                            ->modalHeading('Clear All Confluence Cache')
                            ->modalDescription('This will clear the Confluence cache for ALL users. This is a system-wide operation.')
                            ->action(function () {
                                static::clearAllCache();
                            }),
                    ]),
                ]),
        ];
    }

    /**
     * Clear the current user's Confluence cache
     */
    protected static function clearUserCache(): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $userId = $user->id;
        $assignmentsHash = md5(json_encode([
            method_exists($user, 'getConfluenceSpaceKeys')
                ? $user->getConfluenceSpaceKeys()
                : [],
            method_exists($user, 'getConfluencePageAssignments')
                ? $user->getConfluencePageAssignments()
                : [],
            method_exists($user, 'getConfluenceExcludedPages')
                ? $user->getConfluenceExcludedPages()
                : [],
        ]));

        // Clear Laravel cache for this user
        $cacheKey = "confluence_pages_user_{$userId}_{$assignmentsHash}";
        Cache::forget($cacheKey);

        // Clear Sushi cache file (shared across all users)
        $sushiFile = storage_path('framework/cache/sushi-zynqa-filament-confluence-models-confluence-page.sqlite');
        if (file_exists($sushiFile)) {
            @unlink($sushiFile);
        }

        // Clear Confluence API caches
        Cache::forget('confluence_spaces');

        \Filament\Notifications\Notification::make()
            ->title('Cache Cleared Successfully')
            ->body('Your Confluence cache has been cleared. Fresh data will be loaded on your next visit.')
            ->success()
            ->send();
    }

    /**
     * Clear all Confluence caches for all users (admin only)
     */
    protected static function clearAllCache(): void
    {
        // Clear all Confluence-related cache keys
        Cache::forget('confluence_spaces');

        // Clear Sushi cache file
        $sushiFile = storage_path('framework/cache/sushi-zynqa-filament-confluence-models-confluence-page.sqlite');
        if (file_exists($sushiFile)) {
            @unlink($sushiFile);
        }

        \Filament\Notifications\Notification::make()
            ->title('All Cache Cleared')
            ->body('All Confluence caches have been cleared system-wide.')
            ->success()
            ->send();
    }

    /**
     * Get cache status badge text
     */
    public static function getCacheStatusBadge(): string
    {
        $sushiFile = storage_path('framework/cache/sushi-zynqa-filament-confluence-models-confluence-page.sqlite');

        if (! file_exists($sushiFile)) {
            return 'No Cache';
        }

        $lastModified = \Carbon\Carbon::createFromTimestamp(filemtime($sushiFile));

        if ($lastModified->isToday()) {
            return 'Fresh';
        }

        if ($lastModified->isYesterday()) {
            return 'Stale';
        }

        return 'Old';
    }

    /**
     * Get cache status badge color
     */
    public static function getCacheBadgeColor(): string
    {
        $sushiFile = storage_path('framework/cache/sushi-zynqa-filament-confluence-models-confluence-page.sqlite');

        if (! file_exists($sushiFile)) {
            return 'gray';
        }

        $lastModified = \Carbon\Carbon::createFromTimestamp(filemtime($sushiFile));

        if ($lastModified->isToday()) {
            return 'success'; // Green
        }

        if ($lastModified->isYesterday()) {
            return 'warning'; // Yellow
        }

        return 'danger'; // Red
    }
}
