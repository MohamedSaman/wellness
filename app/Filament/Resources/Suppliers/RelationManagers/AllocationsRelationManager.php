<?php

namespace App\Filament\Resources\Suppliers\RelationManagers;

use App\Exceptions\PosException;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\ShopMoney;
use App\Models\SupplierAllocation;
use App\Models\User;
use App\Services\SupplierPayablesService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Which payment or credit paid which invoice, newest first. Insert-only:
 * an allocation made to the wrong invoice is undone (a row with the amount
 * negated) and made again with Allocate credit (purchasing.manage).
 */
class AllocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'allocations';

    protected static ?string $title = 'Allocations';

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
        $payables = app(SupplierPayablesService::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['credit.payment', 'credit.supplierReturn', 'payable.goodsReceipt', 'user', 'reversal']))
            ->columns([
                TextColumn::make('allocated_on')->label('Date')->date()->sortable(),
                TextColumn::make('credit')
                    ->label('From')
                    ->state(fn (SupplierAllocation $record): string => $record->credit->label()),
                TextColumn::make('payable')
                    ->label('To')
                    ->state(fn (SupplierAllocation $record): string => $payables->describe($record->payable)),
                TextColumn::make('amount')
                    ->formatStateUsing(fn (int $state): ?string => ShopMoney::format($state))
                    ->color(fn (int $state): ?string => $state < 0 ? 'danger' : null)
                    ->description(fn (SupplierAllocation $record): ?string => $record->reversal !== null ? 'Undone' : null),
                TextColumn::make('note')->limit(60)->wrap()->placeholder('-'),
                TextColumn::make('user.name')->label('By')->placeholder('-'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('undoAllocation')
                    ->label('Undo')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->visible(fn (SupplierAllocation $record): bool => SupplierResource::managesPayables() && ! $record->isReversal() && $record->reversal === null)
                    ->authorize(fn (): bool => SupplierResource::managesPayables())
                    ->modalDescription('The invoice is owed again and the money goes back to the supplier\'s credit, to allocate to the right invoice.')
                    ->schema([Textarea::make('reason')->label('Why')->required()->rows(2)->maxLength(500)])
                    ->action(function (array $data, SupplierAllocation $record, Action $action): void {
                        $user = auth()->user();
                        abort_unless($user instanceof User, 403);

                        try {
                            app(SupplierPayablesService::class)->reverseAllocation($record, (string) $data['reason'], $user);
                        } catch (PosException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();
                            $action->halt();
                        }

                        Notification::make()->title('Allocation undone')->success()->send();
                        $this->redirect(SupplierResource::getUrl('view', ['record' => $this->getOwnerRecord()]));
                    }),
            ]);
    }
}
