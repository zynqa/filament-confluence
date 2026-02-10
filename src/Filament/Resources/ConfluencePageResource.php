<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Zynqa\FilamentConfluence\Filament\Resources\ConfluencePageResource\Pages;
use Zynqa\FilamentConfluence\Models\ConfluencePage;

class ConfluencePageResource extends Resource
{
    protected static ?string $model = ConfluencePage::class;

    protected static ?string $slug = 'knowledge';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Knowledge';

    protected static ?string $modelLabel = 'Knowledge Base Page';

    protected static ?string $pluralModelLabel = 'Knowledge Base';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->description(fn (ConfluencePage $record): string => (string) ($record->space_key ?? ''))
                    ->tooltip(function (Tables\Columns\TextColumn $column): ?string {
                        $state = $column->getState();

                        if (strlen($state) <= 50) {
                            return null;
                        }

                        return $state;
                    }),

                Tables\Columns\TextColumn::make('space_key')
                    ->label('Space')
                    ->badge()
                    ->sortable()
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('author_name')
                    ->label('Author')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('space_key')
                    ->label('Space')
                    ->multiple()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                // No bulk actions for read-only resource
            ])
            ->defaultSort('title', 'asc')
            ->poll('30s'); // Auto-refresh every 30 seconds
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListConfluencePages::route('/'),
            'view' => Pages\ViewConfluencePage::route('/{record}'),
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
