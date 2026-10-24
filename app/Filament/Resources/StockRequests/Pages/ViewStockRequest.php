<?php

namespace App\Filament\Resources\StockRequests\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\User;
use App\Services\Data\FulfilLine;
use App\Services\StockRequestService;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * A stock request: what was asked for, sent and still to send, and its
 * transfers. Send stock (stock.transfer) posts a transfer for what is
 * entered, first expiry first out unless a batch is chosen; Print gives the
 * request with a pick list; Cancel ends it.
 */
class ViewStockRequest extends ViewRecord
{
    protected static string $resource = StockRequestResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->loadMissing(['items.variant.product', 'fromLocation', 'toLocation', 'requester', 'transfers.user']);
    }

    private function stockRequest(): StockRequest
    {
        /** @var StockRequest $record */
        $record = $this->getRecord();

        return $record;
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->fulfilAction(),
            Action::make('print')
                ->label('Print / pick list')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn (): string => route('stock.print.request', ['stockRequest' => $this->stockRequest()]).'?print=1', shouldOpenInNewTab: true),
            Action::make('cancel')
                ->label('Cancel request')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (): bool => ($user = self::user()) !== null && app(StockRequestService::class)->mayCancel($this->stockRequest(), $user))
                ->requiresConfirmation()
                ->modalDescription('Nothing more will be sent for this request. What was already sent stays sent.')
                ->action(function (Action $action): void {
                    try {
                        app(StockRequestService::class)->cancel($this->stockRequest(), self::user() ?? abort(403));
                    } catch (PosException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();
                        $action->halt();
                    }

                    Notification::make()->title('Request cancelled')->success()->send();
                    $action->redirect(StockRequestResource::getUrl('view', ['record' => $this->stockRequest()]));
                }),
        ];
    }

    /**
     * Send stock: each item still outstanding, filled in with what the source
     * can send now (what is outstanding, less what it is short of), in the
     * unit asked for when that is whole, else in base units.
     */
    private function fulfilAction(): Action
    {
        $canSend = fn (): bool => (self::user()?->can('stock.transfer') ?? false) && $this->stockRequest()->isOpen();

        return Action::make('fulfil')
            ->label('Send stock')
            ->icon(Heroicon::OutlinedTruck)
            ->visible($canSend)
            ->authorize($canSend)
            ->modalHeading(fn (): string => 'Send stock for '.$this->stockRequest()->request_number)
            ->modalDescription(fn (): string => 'From '.$this->stockRequest()->fromLocation->name.' to '.$this->stockRequest()->toLocation->name.'. Posts a transfer now, first expiry first out unless you pick a batch. Leave an item at 0 to send it later.')
            ->modalWidth('5xl')
            ->modalSubmitActionLabel('Send')
            ->fillForm(fn (): array => ['lines' => $this->defaultLines()])
            ->schema([
                Repeater::make('lines')
                    ->hiddenLabel()
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->columns(5)
                    ->schema([
                        Hidden::make('item_id'),
                        Hidden::make('variant_id'),
                        TextInput::make('label')->label('Item')->disabled()->dehydrated(false)->columnSpan(2),
                        Select::make('unit_id')
                            ->label('Unit')
                            ->options(fn (Get $get): array => StockTransferResource::unitOptions($get('variant_id')))
                            ->placeholder('Base unit'),
                        TextInput::make('quantity')
                            ->label('Send now')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.001)
                            ->required()
                            ->helperText(fn (Get $get): ?string => $get('hint')),
                        Select::make('batch_id')
                            ->label('Batch')
                            ->options(fn (Get $get): array => StockTransferResource::batchOptions($get('variant_id'), $this->stockRequest()->from_location_id))
                            ->placeholder('First expiry first out')
                            ->visible(fn (Get $get): bool => StockTransferResource::tracksBatches($get('variant_id'))),
                        Hidden::make('hint')->dehydrated(false),
                    ]),
                Textarea::make('note')->rows(2)->maxLength(500)->placeholder('For '.$this->stockRequest()->request_number),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    $transfer = app(StockRequestService::class)->fulfil(
                        $this->stockRequest(),
                        array_values(array_map(fn (array $line): FulfilLine => new FulfilLine(
                            (int) $line['item_id'],
                            (float) ($line['quantity'] ?? 0),
                            filled($line['unit_id'] ?? null) ? (int) $line['unit_id'] : null,
                            filled($line['batch_id'] ?? null) ? (int) $line['batch_id'] : null,
                        ), $data['lines'] ?? [])),
                        self::user() ?? abort(403),
                        $data['note'] ?? null,
                    );
                } catch (PosException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title("Sent: transfer {$transfer->transfer_number}")->success()->send();
                $action->redirect(StockRequestResource::getUrl('view', ['record' => $this->stockRequest()]));
            });
    }

    /** @return list<array<string, mixed>> */
    private function defaultLines(): array
    {
        $lines = [];

        foreach (app(StockRequestService::class)->pickList($this->stockRequest()) as $pick) {
            /** @var StockRequestItem $item */
            $item = $pick['item'];
            $canSend = Quantity::normalize($pick['outstanding'] - $pick['short']);
            $factor = (float) $item->unit_factor;
            $inUnit = $factor != 1.0 && Quantity::isWhole($canSend / $factor);
            $unit = $item->variant->product->unit;

            $lines[] = [
                'item_id' => $item->getKey(),
                'variant_id' => $item->product_variant_id,
                'label' => StockTransferResource::itemLabel($item->variant),
                'unit_id' => $inUnit ? $item->variant_unit_id : null,
                'quantity' => $inUnit ? Quantity::normalize($canSend / $factor) : $canSend,
                'hint' => 'Still to send: '.Quantity::format($pick['outstanding']).' '.$unit
                    .($pick['short'] > 0 ? '; '.$this->stockRequest()->fromLocation->name.' is short of '.Quantity::format($pick['short']) : ''),
            ];
        }

        return $lines;
    }
}
