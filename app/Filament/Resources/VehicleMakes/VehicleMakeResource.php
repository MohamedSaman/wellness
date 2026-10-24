<?php

namespace App\Filament\Resources\VehicleMakes;

use App\Enums\VehicleType;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\VehicleMakes\Pages\ManageVehicleMakes;
use App\Models\VehicleMake;
use App\Support\CurrentShop;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Vehicle makes with their models. This is shared reference data used for
 * spare-part fitment, so it is not tied to one shop. Hidden (menu and
 * pages) unless the shop has the vehicle_fitment module.
 */
class VehicleMakeResource extends PermissionResource
{
    protected static ?string $model = VehicleMake::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Vehicles';

    protected static ?string $modelLabel = 'vehicle make';

    protected static ?int $navigationSort = 40;

    public static function canAccess(): bool
    {
        return (app(CurrentShop::class)->get()?->hasModule('vehicle_fitment') ?? false) && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Make')->required()->maxLength(255)->unique(ignoreRecord: true),
            Repeater::make('models')
                ->relationship()
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    Select::make('type')
                        ->options(collect(VehicleType::cases())->mapWithKeys(fn (VehicleType $type) => [$type->value => ucwords(str_replace('_', ' ', $type->value))]))
                        ->default(VehicleType::Motorcycle->value)
                        ->required(),
                    TextInput::make('year_from')->numeric()->minValue(1900)->maxValue(2100),
                    TextInput::make('year_to')->numeric()->minValue(1900)->maxValue(2100),
                ])
                ->columns(4)
                ->deletable(false)
                ->addActionLabel('Add model'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Make')->searchable()->sortable(),
                TextColumn::make('models_count')->counts('models')->label('Models'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageVehicleMakes::route('/'),
        ];
    }
}
