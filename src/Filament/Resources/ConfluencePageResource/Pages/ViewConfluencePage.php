<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Filament\Resources\ConfluencePageResource\Pages;

use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Cache;
use Zynqa\FilamentConfluence\Filament\Resources\ConfluencePageResource;
use Zynqa\FilamentConfluence\Models\ConfluencePage;
use Zynqa\FilamentConfluence\Services\ConfluenceService;

class ViewConfluencePage extends ViewRecord
{
    protected static string $resource = ConfluencePageResource::class;

    public ?array $fullPageData = null;

    public function mount(int | string $record): void
    {
        parent::mount($record);

        // Fetch full page data with content from Confluence API
        $service = app(ConfluenceService::class);
        $this->fullPageData = $service->getPage((string) $this->record->page_id);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    // Clear page cache
                    $service = app(ConfluenceService::class);
                    $service->clearPageCache((string) $this->record->page_id);

                    // Reload page data
                    $this->fullPageData = $service->getPage((string) $this->record->page_id);

                    \Filament\Notifications\Notification::make()
                        ->success()
                        ->title('Page refreshed from Confluence')
                        ->send();
                }),
        ];
    }

    public function getTitle(): string
    {
        return $this->record->title;
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Content')
                    ->schema([
                        Infolists\Components\TextEntry::make('content')
                            ->html()
                            ->prose()
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->state(function () {
                                return $this->fullPageData['body']['view']['value']
                                    ?? $this->fullPageData['body']['storage']['value']
                                    ?? '<p>No content available</p>';
                            }),
                    ])
                    ->collapsed(false),
            ]);
    }
}
