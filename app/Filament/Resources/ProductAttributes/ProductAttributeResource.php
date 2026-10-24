<?php

namespace App\Filament\Resources\ProductAttributes;

use App\Enums\AttributeType;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\ProductAttributes\Pages\ManageProductAttributes;
use App\Filament\Support\ShopRules;
use App\Models\ProductAttribute;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ProductAttributeResource extends PermissionResource
{
    protected static ?string $model = ProductAttribute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Attributes';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)
                ->helperText('For example Size, Colour, Helmet Type, Material.'),
            TextInput::make('code')->required()->maxLength(50)->alphaDash()
                ->helperText('Short internal key, such as size or colour.')
                ->unique(ignoreRecord: true, modifyRuleUsing: ShopRules::limitToCurrentShop(...)),
            Select::make('type')
                ->options(collect(AttributeType::cases())->mapWithKeys(fn (AttributeType $type) => [$type->value => ucfirst($type->value)]))
                ->default(AttributeType::Select->value)
                ->required()
                ->live(),
            TextInput::make('unit')->maxLength(20)->helperText('Optional, for example g or mm.'),
            Repeater::make('options')
                ->relationship()
                ->visible(fn (Get $get): bool => $get('type') === AttributeType::Select->value)
                ->schema([
                    TextInput::make('value')->required()->maxLength(255),
                    TextInput::make('code')->maxLength(50)->helperText('Used in generated SKUs (M, BLK).'),
                ])
                ->columns(2)
                ->orderColumn('sort_order')
                ->deletable(false)
                ->addActionLabel('Add option'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('type')->badge()->formatStateUsing(fn (AttributeType $state): string => ucfirst($state->value)),
                TextColumn::make('options_count')->counts('options')->label('Options'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProductAttributes::route('/'),
        ];
    }
}
