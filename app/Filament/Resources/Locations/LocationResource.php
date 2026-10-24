<?php

namespace App\Filament\Resources\Locations;

use App\Exceptions\PosException;
use App\Filament\Resources\Locations\Pages\ManageLocations;
use App\Filament\Resources\PermissionResource;
use App\Models\Location;
use App\Services\LocationService;
use App\Support\CurrentShop;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Stock locations (multi_location): the pools, stores or branches stock is
 * held in, e.g. General Pharmacy and Doctor's Medicines. Every change goes
 * through LocationService (one default per shop, no deactivating a location
 * that holds stock or has a till). Hidden unless the shop has multi_location.
 */
class LocationResource extends PermissionResource
{
    protected static ?string $model = Location::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 15;

    public static function canAccess(): bool
    {
        return (app(CurrentShop::class)->get()?->hasModule('multi_location') ?? false) && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')
                ->required()
                ->maxLength(20)
                ->regex('/^[A-Za-z0-9-]+$/')
                ->helperText('Short code shown in lists and reports, e.g. GEN or DOC.'),
            TextInput::make('address')->maxLength(255)->columnSpanFull(),
            TextInput::make('sort_order')
                ->label('Order')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0)
                ->helperText('Lists, reports and receipts show locations in this order.'),
            Toggle::make('is_default')
                ->label('Default location')
                ->helperText('New stock and transfers start here unless a user has their own default. Exactly one location is the default.'),
            Toggle::make('is_sellable')
                ->label('Sell from here')
                ->default(true)
                ->helperText('Turn off for a back store or a quarantine shelf.'),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('Only a location with no stock and no till can be deactivated.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount(['devices' => fn (Builder $devices) => $devices->where('is_active', true)]))
            ->columns([
                TextColumn::make('code')->weight('bold')->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                IconColumn::make('is_default')->label('Default')->boolean(),
                IconColumn::make('is_sellable')->label('Sell from here')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('devices_count')->label('Tills'),
                TextColumn::make('sort_order')->label('Order')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('sort_order')->orderBy('name'))
            ->recordActions([
                EditAction::make()
                    ->using(fn (Location $record, array $data, Action $action): Location => static::save($action, fn (): Location => app(LocationService::class)->update($record, $data, static::user() ?? abort(403)))),
            ]);
    }

    /**
     * Run a LocationService call; a business-rule failure is shown and the form stays open.
     *
     * @param  callable(): Location  $callback
     */
    public static function save(Action $action, callable $callback): Location
    {
        try {
            return $callback();
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            $action->halt();

            throw $exception;
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLocations::route('/'),
        ];
    }
}
