<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\PosException;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->loadMissing(['items.variant.product', 'supplier', 'location', 'user', 'goodsReceipts']);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Purchase order '.$this->order()->po_number;
    }

    private function order(): PurchaseOrder
    {
        $record = $this->getRecord();
        abort_unless($record instanceof PurchaseOrder, 404);

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
        $manages = fn (): bool => $this->user()->can('purchasing.manage');

        return [
            Action::make('print')
                ->label('Print')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn (): string => route('purchasing.print.order', ['order' => $this->order()]).'?print=1', shouldOpenInNewTab: true),
            EditAction::make()->visible(fn (): bool => $this->order()->status === PurchaseOrderStatus::Draft),
            Action::make('send')
                ->label('Mark as sent')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->requiresConfirmation()
                ->modalDescription('Once sent, the order can only be received against or cancelled. Print it for the supplier from this page.')
                ->visible(fn (): bool => $this->order()->status === PurchaseOrderStatus::Draft && $manages())
                ->authorize($manages)
                ->action(fn (Action $action) => $this->run($action, fn () => app(PurchaseOrderService::class)->send($this->order(), $this->user()), 'Order marked as sent')),
            Action::make('receive')
                ->label('Receive goods')
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->color('success')
                ->visible(fn (): bool => $this->order()->status->isOpen() && $this->user()->can('purchasing.receive'))
                ->url(fn (): string => GoodsReceiptResource::getUrl('create').'?order='.$this->order()->uuid),
            Action::make('cancel')
                ->label('Cancel order')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Nothing more is expected on this order. Whatever has already arrived stays received.')
                ->visible(fn (): bool => ! in_array($this->order()->status, [PurchaseOrderStatus::Received, PurchaseOrderStatus::Cancelled], true) && $manages())
                ->authorize($manages)
                ->action(fn (Action $action) => $this->run($action, fn () => app(PurchaseOrderService::class)->cancel($this->order(), $this->user()), 'Order cancelled')),
        ];
    }

    private function run(Action $action, callable $callback, string $done): void
    {
        try {
            $callback();
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
            $action->halt();
        }

        Notification::make()->title($done)->success()->send();
        $this->redirect(PurchaseOrderResource::getUrl('view', ['record' => $this->order()]));
    }
}
