<?php

namespace App\Filament\Resources\LabelPrints;

use App\Filament\Resources\LabelPrints\Pages\ListLabelPrints;
use App\Filament\Resources\PermissionResource;
use App\Filament\Support\LabelActions;
use App\Models\LabelPrint;
use App\Models\User;
use App\Services\Labels\LabelService;
use App\Support\CurrentShop;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The label print log (labels module, docs/PHARMACY.md §9, branch 7b): who
 * printed which labels, how many, with which template, newest first. Users
 * who print labels see their own prints; the owner (labels.manage) sees
 * everyone's. Open prints the same labels again.
 */
class LabelPrintResource extends PermissionResource
{
    protected static ?string $model = LabelPrint::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Label print log';

    protected static ?string $modelLabel = 'label print';

    protected static ?int $navigationSort = 21;

    public static function canAccess(): bool
    {
        return (app(CurrentShop::class)->get()?->hasModule('labels') ?? false) && parent::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = static::user();

        return parent::getEloquentQuery()
            ->with(['user'])
            ->when(! ($user?->can('labels.manage') ?? false), fn (Builder $query) => $query->where('user_id', $user?->getKey() ?? 0));
    }

    public static function table(Table $table): Table
    {
        $manages = fn (): bool => static::user()?->can('labels.manage') ?? false;

        return $table
            ->columns([
                TextColumn::make('created_at')->label('Printed')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('user.name')->label('By')->placeholder('-')->visible($manages),
                TextColumn::make('source')
                    ->label('From')
                    ->state(fn (LabelPrint $record): string => LabelService::SOURCES[$record->source].($record->source_label === null ? '' : ": {$record->source_label}"))
                    ->wrap(),
                TextColumn::make('items')
                    ->label('Labels of')
                    ->state(fn (LabelPrint $record): string => $record->summary())
                    ->wrap(),
                TextColumn::make('labels_count')->label('Labels')->alignEnd()->sortable(),
                TextColumn::make('template_name')->label('Template')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label('By')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->visible($manages),
                SelectFilter::make('source')->label('From')->options(LabelService::SOURCES),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->url(fn (LabelPrint $record): string => LabelActions::printUrl($record), shouldOpenInNewTab: true),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLabelPrints::route('/'),
        ];
    }
}
