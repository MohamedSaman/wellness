<?php

namespace App\Filament\Resources\Suppliers\RelationManagers;

use App\Enums\SupplierPaymentMethod;
use App\Exceptions\PosException;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\ShopMoney;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\SupplierService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Payments made to the supplier (purchasing), with what of each is not yet
 * set against an invoice. Recorded from the supplier's page; never edited
 * or deleted: a wrong one is reversed (a payment with the amount negated,
 * its allocations undone), then recorded again.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

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
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'reversal', 'credit']))
            ->columns([
                TextColumn::make('paid_on')->label('Paid on')->date()->sortable(),
                TextColumn::make('amount')
                    ->formatStateUsing(fn (int $state): ?string => ShopMoney::format($state))
                    ->color(fn (int $state): ?string => $state < 0 ? 'danger' : null)
                    ->description(fn (SupplierPayment $record): ?string => $record->reversal !== null ? 'Reversed' : null),
                TextColumn::make('unallocated')
                    ->label('Credit left')
                    ->state(fn (SupplierPayment $record): ?int => $record->credit?->unallocated())
                    ->formatStateUsing(fn (?int $state): ?string => $state ? ShopMoney::format($state) : '-')
                    ->tooltip('What of the payment is not yet set against an invoice.'),
                TextColumn::make('method')->formatStateUsing(fn (SupplierPaymentMethod $state): string => $state->label()),
                TextColumn::make('reference')->placeholder('-'),
                TextColumn::make('notes')->limit(60)->wrap()->placeholder('-'),
                TextColumn::make('user.name')->label('By')->placeholder('-'),
            ])
            ->defaultSort('paid_on', 'desc')
            ->recordActions([
                Action::make('reversePayment')
                    ->label('Reverse')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->visible(fn (SupplierPayment $record): bool => SupplierResource::managesPayables() && ! $record->isReversal() && $record->reversal === null)
                    ->authorize(fn (): bool => SupplierResource::managesPayables())
                    ->modalDescription('Only for a payment recorded by mistake: a payment with the amount negated takes it back out of the balance, and the invoices it paid are owed again. Then record the right one.')
                    ->schema([Textarea::make('reason')->label('Why')->required()->rows(2)->maxLength(500)])
                    ->action(function (array $data, SupplierPayment $record, Action $action): void {
                        $user = auth()->user();
                        abort_unless($user instanceof User, 403);

                        try {
                            app(SupplierService::class)->reversePayment($record, (string) $data['reason'], $user);
                        } catch (PosException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();
                            $action->halt();
                        }

                        Notification::make()->title('Payment reversed')->success()->send();
                        $this->redirect(SupplierResource::getUrl('view', ['record' => $this->getOwnerRecord()]));
                    }),
            ]);
    }
}
