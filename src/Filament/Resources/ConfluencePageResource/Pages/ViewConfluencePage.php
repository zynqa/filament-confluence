<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Filament\Resources\ConfluencePageResource\Pages;

use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Zynqa\FilamentConfluence\Filament\Resources\ConfluencePageResource;
use Zynqa\FilamentConfluence\Services\ConfluenceContentTransformer;
use Zynqa\FilamentConfluence\Services\ConfluenceService;

class ViewConfluencePage extends ViewRecord
{
    protected static string $resource = ConfluencePageResource::class;

    public ?array $fullPageData = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Fetch full page data with content from Confluence API
        $service = app(ConfluenceService::class);
        $this->fullPageData = $service->getPage((string) $this->record->page_id);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    // Clear page cache
                    $service = app(ConfluenceService::class);
                    $service->clearPageCache((string) $this->record->page_id);

                    // Reload page data
                    $this->fullPageData = $service->getPage((string) $this->record->page_id);

                    Notification::make()
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

    public function infolist(Schema $schema): Schema
    {
        return $infolist
            ->schema([
                Section::make('Content')
                    ->schema([
                        TextEntry::make('content')
                            ->html()
                            ->prose()
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->state(function () {
                                $content = $this->fullPageData['body']['view']['value']
                                    ?? $this->fullPageData['body']['storage']['value']
                                    ?? '<p>No content available</p>';

                                return app(ConfluenceContentTransformer::class)->transform($content);
                            }),
                    ])
                    ->collapsed(false),
            ]);
    }
}
