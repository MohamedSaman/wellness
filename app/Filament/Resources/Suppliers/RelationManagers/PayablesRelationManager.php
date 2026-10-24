<?php

namespace App\Filament\Resources\Suppliers\RelationManagers;

use App\Enums\PayableStatus;
use App\Exceptions\PosException;
use App\Filament\Resources\SupplierPayables\SupplierPayableResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\SupplierPayable;
use App\Models\User;
use App\Services\SupplierService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The supplier's invoices (payables): its posted GRNs and opening entries,
 * oldest due first, unpaid and part-paid ones by default. A due date is
 * moved here, and a wrong opening invoice is reversed (purchasing.manage).
 */
class PayablesRelationManager extends RelationManager
{
    protected static string $relationship = 'payables';

    protected static ?string $title = 'Invoices';

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
        return SupplierPayableResource::payablesTable($table, withSupplier: false)
            ->recordActions([
                SupplierPayableResource::dueDateAction(),
                Action::make('reverseOpeningInvoice')
                    ->label('Reverse')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    // Opening invoices only; the lump sum is reversed from the page's Opening balance menu.
                    ->visible(fn (SupplierPayable $record): bool => SupplierResource::managesPayables()
                        && $record->supplier_opening_balance_id !== null
                        && $record->invoice_no !== null
                        && $record->status !== PayableStatus::Reversed)
                    ->authorize(fn (): bool => SupplierResource::managesPayables())
                    ->modalHeading('Reverse this opening invoice')
                    ->modalDescription('For an opening invoice entered by mistake: an entry with the amount negated takes it back out, and what was paid on it becomes credit again. Then enter the right one.')
                    ->schema([Textarea::make('reason')->label('Why')->required()->rows(2)->maxLength(500)])
                    ->action(function (array $data, SupplierPayable $record, Action $action): void {
                        $user = auth()->user();
                        abort_unless($user instanceof User, 403);
                        $entry = $record->openingBalance;
                        abort_if($entry === null, 404);

                        try {
                            app(SupplierService::class)->reverseOpeningBalance($entry, (string) $data['reason'], $user);
                        } catch (PosException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();
                            $action->halt();
                        }

                        Notification::make()->title('Opening invoice reversed')->success()->send();
                        $this->redirect(SupplierResource::getUrl('view', ['record' => $this->getOwnerRecord()]));
                    }),
            ]);
    }
}
