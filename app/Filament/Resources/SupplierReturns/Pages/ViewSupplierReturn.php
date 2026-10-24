<?php

namespace App\Filament\Resources\SupplierReturns\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\SupplierReturns\SupplierReturnResource;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Services\SupplierReturnService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class ViewSupplierReturn extends ViewRecord
{
    protected static string $resource = SupplierReturnResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->loadMissing(['items.variant.product', 'supplier', 'location', 'user']);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Supplier return '.$this->supplierReturn()->return_number;
    }

    private function supplierReturn(): SupplierReturn
    {
        $record = $this->getRecord();
        abort_unless($record instanceof SupplierReturn, 404);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        $manages = fn (): bool => auth()->user()?->can('purchasing.manage') ?? false;

        return [
            Action::make('print')
                ->label('Print return note')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn (): string => route('purchasing.print.return', ['supplierReturn' => $this->supplierReturn()]).'?print=1', shouldOpenInNewTab: true),
            Action::make('creditNote')
                ->label('Credit note no.')
                ->icon(Heroicon::OutlinedDocumentCheck)
                ->visible($manages)
                ->authorize($manages)
                ->fillForm(fn (): array => ['credit_note_no' => $this->supplierReturn()->credit_note_no])
                ->schema([TextInput::make('credit_note_no')->label('Supplier credit note no.')->maxLength(50)])
                ->action(function (array $data, Action $action): void {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);

                    try {
                        app(SupplierReturnService::class)->setCreditNote($this->supplierReturn(), $data['credit_note_no'] ?? null, $user);
                    } catch (PosException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();
                        $action->halt();
                    }

                    Notification::make()->title('Credit note saved')->success()->send();
                    $this->refreshFormData(['credit_note_no']);
                }),
        ];
    }
}
