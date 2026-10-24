<?php

namespace App\Filament\Resources\Suppliers\RelationManagers;

use App\Enums\SupplierReturnReason;
use App\Filament\Resources\SupplierReturns\SupplierReturnResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\ShopMoney;
use App\Models\SupplierReturn;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Stock sent back to the supplier (purchasing), newest first; each opens its own page. */
class SupplierReturnsRelationManager extends RelationManager
{
    protected static string $relationship = 'supplierReturns';

    protected static ?string $title = 'Returns';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return SupplierResource::showsBalance();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('return_number')->label('Return')->weight('bold'),
                TextColumn::make('returned_on')->label('Returned')->date()->sortable(),
                TextColumn::make('reason')->formatStateUsing(fn (SupplierReturnReason $state): string => $state->label()),
                TextColumn::make('credit_note_no')->label('Credit note')->placeholder('-'),
                TextColumn::make('total')->label('Credit')->formatStateUsing(fn (int $state): ?string => ShopMoney::format($state)),
            ])
            ->defaultSort('returned_on', 'desc')
            ->recordUrl(fn (SupplierReturn $record): ?string => (auth()->user()?->can('view', $record) ?? false) ? SupplierReturnResource::getUrl('view', ['record' => $record]) : null);
    }
}
