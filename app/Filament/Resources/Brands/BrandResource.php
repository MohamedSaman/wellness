<?php

namespace App\Filament\Resources\Brands;

use App\Filament\Resources\Brands\Pages\ManageBrands;
use App\Filament\Resources\PermissionResource;
use App\Filament\Support\ShopRules;
use App\Models\Brand;
use App\Support\CurrentShop;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class BrandResource extends PermissionResource
{
    protected static ?string $model = Brand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 20;

    /** A pharmacy's brands are its manufacturers (the medicine form's Manufacturer field). */
    public static function getModelLabel(): string
    {
        return app(CurrentShop::class)->get()?->hasModule('pharmacy') ? 'manufacturer' : 'brand';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true, modifyRuleUsing: ShopRules::limitToCurrentShop(...)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('products_count')->counts('products')->label('Products'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data): array => [...$data, 'slug' => Str::slug($data['name'])]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBrands::route('/'),
        ];
    }
}
