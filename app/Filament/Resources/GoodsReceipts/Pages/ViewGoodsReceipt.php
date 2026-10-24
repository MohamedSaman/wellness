<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Support\LabelActions;
use App\Filament\Support\PayablesForm;
use App\Models\GoodsReceipt;
use App\Models\SupplierPayable;
use App\Models\User;
use App\Services\GoodsReceiptService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class ViewGoodsReceipt extends ViewRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->loadMissing(['items.variant.product', 'supplier', 'location', 'purchaseOrder', 'poster', 'reversalOf', 'reversal', 'payable']);
    }

    public function getTitle(): string|Htmlable
    {
        $receipt = $this->receipt();

        return $receipt->isPosted() ? "Goods received {$receipt->grn_number}" : 'Goods received (draft)';
    }

    private function receipt(): GoodsReceipt
    {
        $record = $this->getRecord();
        abort_unless($record instanceof GoodsReceipt, 404);

        return $record;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    protected function getHeaderActions(): array
    {
        $receives = fn (): bool => $this->user()->can('purchasing.receive');
        $manages = fn (): bool => $this->user()->can('purchasing.manage');
        $draft = fn (): bool => ! $this->receipt()->isPosted();

        return [
            Action::make('print')
                ->label('Print')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->visible(fn (): bool => $this->receipt()->isPosted())
                ->url(fn (): string => route('purchasing.print.receipt', ['receipt' => $this->receipt()]).'?print=1', shouldOpenInNewTab: true),
            LabelActions::receiptAction(),
            EditAction::make()->visible($draft),
            PayablesForm::dueDateAction(
                fn (Model $record): ?SupplierPayable => $record instanceof GoodsReceipt ? $record->payable : null,
                fn () => $this->redirect(GoodsReceiptResource::getUrl('view', ['record' => $this->receipt()])),
            ),
            Action::make('post')
                ->label('Post')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Post this goods received note?')
                ->modalDescription('The stock comes in and the average cost is updated. A posted GRN can\'t be edited: a mistake is put right by reversing it.')
                ->visible(fn (): bool => $draft() && $receives())
                ->authorize($receives)
                ->action(function (Action $action): void {
                    $posted = $this->run($action, fn (): GoodsReceipt => app(GoodsReceiptService::class)->post($this->receipt(), $this->user()));

                    Notification::make()->title("Posted as {$posted->grn_number}: the stock is in")->success()->send();
                    $this->redirect(GoodsReceiptResource::getUrl('view', ['record' => $posted]));
                }),
            Action::make('deleteDraft')
                ->label('Delete draft')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $draft() && $receives())
                ->authorize($receives)
                ->action(function (Action $action): void {
                    $this->run($action, fn () => app(GoodsReceiptService::class)->deleteDraft($this->receipt(), $this->user()));

                    Notification::make()->title('Draft deleted')->success()->send();
                    $this->redirect(GoodsReceiptResource::getUrl('index'));
                }),
            Action::make('reverse')
                ->label('Reverse')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->modalHeading('Reverse this goods received note')
                ->modalDescription('A reversing GRN takes the same quantities back out of the same batches at the same costs and puts the average cost back. Nothing is owed on it any more: what was paid on it becomes the supplier\'s credit. Use it for a wrong entry; return goods to the supplier with a supplier return instead. Then enter the delivery again correctly.')
                ->visible(fn (): bool => $this->receipt()->isPosted() && ! $this->receipt()->isReversal() && $this->receipt()->reversal === null && $manages())
                ->authorize($manages)
                ->schema([
                    Textarea::make('reason')->label('Why')->required()->rows(2)->maxLength(500)->placeholder('Wrong cost typed, entered twice ...'),
                ])
                ->action(function (array $data, Action $action): void {
                    $reversal = $this->run($action, fn (): GoodsReceipt => app(GoodsReceiptService::class)->reverse($this->receipt(), (string) $data['reason'], $this->user()));

                    Notification::make()->title("Reversed by {$reversal->grn_number}")->success()->send();
                    $this->redirect(GoodsReceiptResource::getUrl('view', ['record' => $reversal]));
                }),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function run(Action $action, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
            $action->halt();

            throw $exception;
        }
    }
}
