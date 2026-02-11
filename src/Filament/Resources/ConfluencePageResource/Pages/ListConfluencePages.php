<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Filament\Resources\ConfluencePageResource\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Cache;
use Zynqa\FilamentConfluence\Filament\Resources\ConfluencePageResource;

class ListConfluencePages extends ListRecords
{
    protected static string $resource = ConfluencePageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refresh')
                ->label('Refresh from Confluence')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    // Clear Sushi cache for current user
                    $user = auth()->user();
                    $userId = $user?->id ?? 'guest';

                    // Generate the correct cache key with assignments hash
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

                    $cacheKey = "sushi:confluence_pages_user_{$userId}_{$assignmentsHash}";
                    Cache::forget($cacheKey);

                    // Delete Sushi SQLite database file to force fresh data load
                    $sushiDbPath = storage_path('framework/cache/sushi-zynqa-filament-confluence-models-confluence-page.sqlite');
                    if (file_exists($sushiDbPath)) {
                        @unlink($sushiDbPath);
                    }

                    // Clear service-level caches
                    Cache::forget('confluence_spaces');

                    // Clear space ID and page caches for user's assigned spaces
                    if ($user && method_exists($user, 'getConfluenceSpaceKeys')) {
                        $service = app(\Zynqa\FilamentConfluence\Services\ConfluenceApiClient::class);
                        foreach ($user->getConfluenceSpaceKeys() as $spaceKey) {
                            $service->clearSpaceCache($spaceKey);
                        }
                    }

                    Notification::make()
                        ->success()
                        ->title('Confluence data refreshed successfully')
                        ->send();
                }),

            Actions\Action::make('confluence_settings')
                ->label('Confluence Settings')
                ->icon('heroicon-o-cog-6-tooth')
                ->url(fn (): string => route('filament.app.pages.manage-general-settings'))
                ->visible(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false)
                ->color('gray'),
        ];
    }
}
