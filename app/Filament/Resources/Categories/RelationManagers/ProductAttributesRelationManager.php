<?php

namespace App\Filament\Resources\Categories\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which attributes products in this category use, and which of them create
 * variants (Size, Colour) rather than plain product info (Helmet Type).
 */
class ProductAttributesRelationManager extends RelationManager
{
    protected static string $relationship = 'productAttributes';

    protected static ?string $title = 'Attributes';

    protected static ?string $recordTitleAttribute = 'name';

    /** @return array<int, mixed> */
    private static function pivotFields(): array
    {
        return [
            Toggle::make('is_variant_axis')->label('Creates variants')->default(false)->helperText('Each value becomes a separate sellable variant (Size, Colour).'),
            Toggle::make('is_required')->label('Required')->default(false),
            TextInput::make('sort_order')->label('Sort order')->integer()->required()->default(0)->minValue(0),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(self::pivotFields());
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                IconColumn::make('is_variant_axis')->label('Creates variants')->boolean(),
                IconColumn::make('is_required')->label('Required')->boolean(),
                TextColumn::make('sort_order'),
            ])
            ->headerActions([
                // Lists only this shop's attributes (tenant scope) that this category does not use yet.
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'code'])
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query->orderBy('name'))
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect()->label('Attribute'),
                        ...self::pivotFields(),
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DetachAction::make(),
            ]);
    }
}
