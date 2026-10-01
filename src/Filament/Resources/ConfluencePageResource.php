<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Zynqa\FilamentConfluence\Filament\Resources\ConfluencePageResource\Pages;
use Zynqa\FilamentConfluence\Filament\Resources\ConfluencePageResource\Pages\ListConfluencePages;
use Zynqa\FilamentConfluence\Filament\Resources\ConfluencePageResource\Pages\ViewConfluencePage;
use Zynqa\FilamentConfluence\Models\ConfluencePage;

class ConfluencePageResource extends Resource
{
    protected static ?string $model = ConfluencePage::class;

    protected static ?string $slug = 'knowledge';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Knowledge';

    protected static ?string $modelLabel = 'Knowledge Base Page';

    protected static ?string $pluralModelLabel = 'Knowledge Base';

    protected static ?int $navigationSort = 2;

    /**
     * The host application sets its date format in app.date_format, as the other Zynqa
     * packages expect, so dates read the same here as in the rest of the panel.
     */
    private const DEFAULT_DATE_FORMAT = 'd/m/Y';

    private const LONG_DATE_TIME_FORMAT = 'l, F j, Y \\a\\t g:i:s A';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();

                        if (strlen($state) <= 50) {
                            return null;
                        }

                        return $state;
                    }),

                TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->date(fn (): string => (string) config('app.date_format', self::DEFAULT_DATE_FORMAT))
                    ->dateTimeTooltip(self::LONG_DATE_TIME_FORMAT)
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('space_key')
                    ->label('Space')
                    ->multiple()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                // No bulk actions for read-only resource
            ])
            ->defaultSort('updated_at', 'desc')
            ->poll('30s'); // Auto-refresh every 30 seconds
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConfluencePages::route('/'),
            'view' => ViewConfluencePage::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        // Super admins see everything (already filtered by Sushi model, but keep for consistency)
        if ($user && $user->hasRole('super_admin')) {
            return $query;
        }

        // Regular users - Sushi model already handles filtering in getRows()
        return $query;
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        // Check if user has ANY Confluence access (spaces or pages)
        if (method_exists($user, 'hasConfluenceAccess')) {
            return $user->hasConfluenceAccess();
        }

        // Fallback: check for space keys only (backwards compatibility)
        if (method_exists($user, 'hasConfluenceSpaceKeys')) {
            return $user->hasConfluenceSpaceKeys();
        }

        return false;
    }

    // Read-only resource
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
