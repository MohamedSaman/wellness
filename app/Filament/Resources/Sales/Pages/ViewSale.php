<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Enums\SaleStatus;
use App\Exceptions\PosException;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\ShopMoney;
use App\Models\AuditLog;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemBatch;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\ReturnLine;
use App\Services\OfflineSaleSyncService;
use App\Services\ReceiptService;
use App\Services\ReturnService;
use App\Support\CurrentShop;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ViewSale extends ViewRecord
{
    protected static string $resource = SaleResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->loadMissing(['items.location', 'items.batches', 'returns.items.saleItem', 'returns.user']);
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                $this->reprintAction(),
                $this->reprintRefundAction(),
                $this->reprintVoidAction(),
                $this->invoiceAction(),
            ])->label('Print')->icon('heroicon-o-printer')->color('gray')->button(),
            $this->markReviewedAction(),
            $this->returnAction(),
            $this->voidAction(),
        ];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Returns and refunds (docs/PHARMACY.md §9, branch 9): pick lines of the
     * bill and how many come back of each, in the unit sold or in base units
     * (10 tablets from a box), up to what was sold less what already came
     * back; each line's own "Put back into stock?" (off by default for fridge
     * items and controlled drugs, and for faulty goods); how the refund is
     * paid back. The refund is what the lines were charged (an OPD medicine
     * line: its share of the Medicines amount), shown as it is typed. The
     * refund slip opens once the return is recorded.
     */
    private function returnAction(): Action
    {
        return Action::make('return')
            ->label('Return items')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (): bool => $this->canReturn())
            ->authorize(fn (): bool => $this->canReturn())
            ->modalHeading('Return items')
            ->modalWidth(Width::FiveExtraLarge)
            ->fillForm(fn (): array => [
                'refund_method' => PaymentMethod::Cash->value,
                'items' => $this->returnableItems()->map(fn (SaleItem $item): array => [
                    'sale_item_id' => $item->getKey(),
                    'description' => $item->description,
                    'remaining' => $this->remainingText($item),
                    'quantity' => 0,
                    'unit' => 'sold',
                    'restock' => ReturnService::restocksByDefault($item),
                    // A line that took several batches: say which ones come back (base units).
                    'batches' => $item->batches->count() < 2 ? [] : $item->batches
                        ->filter(fn (SaleItemBatch $batch): bool => $batch->remaining() > 0)
                        ->map(fn (SaleItemBatch $batch): array => [
                            'sale_item_batch_id' => $batch->getKey(),
                            'label' => $batch->label().' — '.Quantity::format($batch->remaining()).' can come back',
                            'quantity' => null,
                        ])->values()->all(),
                ])->values()->all(),
            ])
            ->schema([
                Repeater::make('items')
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->columns(5)
                    ->schema([
                        Hidden::make('sale_item_id'),
                        TextInput::make('description')->disabled()->dehydrated(false)->columnSpan(2),
                        TextInput::make('remaining')->label('Can still return')->disabled()->dehydrated(false),
                        TextInput::make('quantity')->label('Return quantity')->numeric()->minValue(0)->step(0.001)->default(0)->live(debounce: 500),
                        Select::make('unit')
                            ->label('In')
                            ->options(fn (Get $get): array => $this->unitOptions((int) $get('sale_item_id')))
                            ->default('sold')
                            ->selectablePlaceholder(false)
                            ->live()
                            ->visible(fn (Get $get): bool => count($this->unitOptions((int) $get('sale_item_id'))) > 1),
                        Toggle::make('restock')
                            ->label('Put back into stock?')
                            ->inline(false)
                            ->onIcon('heroicon-m-check')
                            ->offIcon('heroicon-m-x-mark')
                            ->helperText(fn (Get $get): ?string => $this->restockHint((int) $get('sale_item_id')))
                            ->columnSpanFull(),
                        Repeater::make('batches')
                            ->label('From which batches (base units)')
                            ->helperText('Leave blank to take the latest expiry first.')
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(2)
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => ($get('batches') ?? []) !== [])
                            ->schema([
                                Hidden::make('sale_item_batch_id'),
                                TextInput::make('label')->hiddenLabel()->disabled()->dehydrated(false),
                                TextInput::make('quantity')->hiddenLabel()->numeric()->minValue(0)->step(0.001)->placeholder('0'),
                            ]),
                    ]),
                Select::make('reason')
                    ->options(ReturnReason::options())
                    ->required()
                    ->native(false)
                    ->live()
                    // Faulty goods stay off the shelf unless the user switches a line back on; so do
                    // fridge items and controlled drugs (pharmacy, docs/PHARMACY.md §6.5).
                    ->afterStateUpdated(fn (?string $state, Get $get, Set $set) => $set('items', array_map(fn (array $row): array => [
                        ...$row,
                        'restock' => (ReturnReason::tryFrom((string) $state)?->restocksByDefault() ?? true)
                            && ReturnService::restocksByDefault($this->saleItem((int) $row['sale_item_id'])),
                    ], $get('items') ?? []))),
                Select::make('refund_method')
                    ->label('Refund by')
                    ->options(collect(PaymentMethod::cases())->mapWithKeys(fn (PaymentMethod $method) => [$method->value => ucwords(str_replace('_', ' ', $method->value))])->all())
                    ->required()
                    ->live(),
                TextInput::make('refund_reference')
                    ->label(fn (Get $get): string => $get('refund_method') === PaymentMethod::BankTransfer->value ? 'Bank transfer reference' : 'Reference')
                    ->maxLength(100)
                    ->required(fn (Get $get): bool => $get('refund_method') === PaymentMethod::BankTransfer->value
                        && (bool) app(CurrentShop::class)->getOrFail()->setting('bank_transfer_reference_required'))
                    ->visible(fn (Get $get): bool => filled($get('refund_method')) && $get('refund_method') !== PaymentMethod::Cash->value),
                Textarea::make('note')->rows(2)->maxLength(500)->placeholder('Optional, e.g. what was wrong with it'),
                Text::make(fn (Get $get): string => $this->refundPreview($get('items') ?? []))
                    ->weight('bold'),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    $return = app(ReturnService::class)->processLines(
                        $this->saleRecord(),
                        $this->returnLines($data['items'] ?? []),
                        ReturnReason::from($data['reason']),
                        PaymentMethod::from($data['refund_method']),
                        $this->user(),
                        refundReference: $data['refund_reference'] ?? null,
                        note: $data['note'] ?? null,
                    );
                } catch (PosException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    $action->halt();

                    return;
                }

                $this->refreshFormData(['status', 'refunded_total']);
                $url = route('pos.receipt.refund', ['sale' => $this->saleRecord(), 'saleReturn' => $return]).'?print=1';
                $this->js('window.open('.json_encode($url).", '_blank', 'width=420,height=720')");

                Notification::make()
                    ->title('Return recorded')
                    ->body('Refund '.$return->return_no.': '.ShopMoney::format($return->refund_total))
                    ->actions([Action::make('slip')->label('Print refund slip')->url($url, shouldOpenInNewTab: true)])
                    ->success()
                    ->send();
            });
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return list<ReturnLine>
     */
    private function returnLines(array $rows): array
    {
        $lines = [];

        foreach ($rows as $row) {
            $batches = [];

            foreach ($row['batches'] ?? [] as $batch) {
                if ((float) ($batch['quantity'] ?? 0) > 0) {
                    $batches[(int) $batch['sale_item_batch_id']] = (float) $batch['quantity'];
                }
            }

            $lines[] = new ReturnLine(
                (int) $row['sale_item_id'],
                (float) ($row['quantity'] ?? 0),
                inBaseUnits: ($row['unit'] ?? 'sold') === 'base',
                restock: (bool) ($row['restock'] ?? false),
                batches: $batches === [] ? null : $batches,
            );
        }

        return $lines;
    }

    /**
     * "Refund: LKR 180.00" for what is typed so far, or why it can't be returned.
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     */
    private function refundPreview(array $rows): string
    {
        $lines = array_values(array_filter($this->returnLines($rows), fn (ReturnLine $line): bool => $line->quantity > 0));

        if ($lines === []) {
            return 'Refund: nothing yet. Enter the quantities that come back.';
        }

        try {
            $preview = app(ReturnService::class)->preview($this->saleRecord()->fresh() ?? $this->saleRecord(), $lines);
        } catch (PosException $exception) {
            return $exception->getMessage();
        }

        return 'Refund: '.ShopMoney::format($preview['total']);
    }

    /** "1 Box (30 Tablet)": what is left of a line, in its unit and, for a pack, in base units. */
    private function remainingText(SaleItem $item): string
    {
        $remaining = Quantity::normalize((float) $item->quantity - (float) $item->returned_quantity);
        $text = trim(Quantity::format($remaining).' '.$item->unit_name);

        if ((float) $item->unit_factor !== 1.0 && $item->base_unit_name !== null) {
            $base = Quantity::normalize((float) $item->base_quantity - (float) $item->returnItems()->sum('base_quantity'));
            $text .= ' ('.Quantity::format($base).' '.$item->base_unit_name.')';
        }

        return $text;
    }

    /**
     * The units a line can be returned in: the unit it was sold in, and base
     * units for a pack.
     *
     * @return array<string, string>
     */
    private function unitOptions(int $saleItemId): array
    {
        $item = $this->saleItem($saleItemId);

        return array_filter([
            'sold' => (string) ($item->unit_name ?: 'as sold'),
            'base' => (float) $item->unit_factor !== 1.0 && $item->base_unit_name !== null ? $item->base_unit_name : null,
        ]);
    }

    private function restockHint(int $saleItemId): ?string
    {
        return ReturnService::restocksByDefault($this->saleItem($saleItemId))
            ? null
            : 'Fridge item or controlled drug: off unless you can vouch for it.';
    }

    private function saleItem(int $saleItemId): SaleItem
    {
        /** @var SaleItem $item */
        $item = $this->saleRecord()->items->firstWhere('id', $saleItemId) ?? abort(404);

        return $item;
    }

    /**
     * Logs the reprint, then opens the duplicate receipt in a new window,
     * which prints itself. The notification links to it in case the browser
     * blocked the window.
     */
    private function reprintAction(): Action
    {
        $canReprint = fn (): bool => $this->user()->can('sales.view_all');

        return Action::make('reprint')
            ->label('Reprint receipt')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->visible($canReprint)
            ->authorize($canReprint)
            ->action(function (): void {
                $sale = $this->saleRecord();
                $reprint = app(ReceiptService::class)->recordReprint($sale, $this->user(), null);
                $url = route('pos.receipt.reprinted', ['sale' => $sale, 'reprint' => $reprint->getKey()]);

                $this->js('window.open('.json_encode($url.'?print=1').", '_blank', 'width=420,height=720')");

                Notification::make()
                    ->title('Duplicate receipt sent to print')
                    ->actions([Action::make('open')->label('Open receipt')->url($url.'?print=1', shouldOpenInNewTab: true)])
                    ->success()
                    ->send();
            });
    }

    /** Reprints one return's refund slip, marked REPRINT. */
    private function reprintRefundAction(): Action
    {
        $canReprint = fn (): bool => $this->user()->can('sales.view_all') && $this->saleRecord()->returns->isNotEmpty();

        return Action::make('reprintRefund')
            ->label('Reprint refund slip')
            ->icon('heroicon-o-arrow-uturn-left')
            ->visible($canReprint)
            ->authorize($canReprint)
            ->schema([
                Select::make('sale_return_id')
                    ->label('Return')
                    ->options(fn (): array => $this->saleRecord()->returns->mapWithKeys(fn (SaleReturn $return): array => [
                        $return->getKey() => $return->return_no.' — '.ShopMoney::format($return->refund_total),
                    ])->all())
                    ->default(fn (): ?int => $this->saleRecord()->returns->last()?->getKey())
                    ->required(),
            ])
            ->action(function (array $data): void {
                $return = $this->saleRecord()->returns->firstWhere('id', (int) $data['sale_return_id']) ?? abort(404);
                $this->openReprint(app(ReceiptService::class)->recordReprint($this->saleRecord(), $this->user(), null, 'refund', $return), 'Refund slip sent to print');
            });
    }

    /** Reprints the void slip of a voided sale, marked REPRINT. */
    private function reprintVoidAction(): Action
    {
        $canReprint = fn (): bool => $this->user()->can('sales.view_all') && $this->saleRecord()->status === SaleStatus::Voided;

        return Action::make('reprintVoid')
            ->label('Reprint void slip')
            ->icon('heroicon-o-x-circle')
            ->visible($canReprint)
            ->authorize($canReprint)
            ->action(fn () => $this->openReprint(app(ReceiptService::class)->recordReprint($this->saleRecord(), $this->user(), null, 'void'), 'Void slip sent to print'));
    }

    /** The A4 invoice, for a patient who asks for a proper bill. */
    private function invoiceAction(): Action
    {
        return Action::make('invoice')
            ->label('A4 invoice')
            ->icon('heroicon-o-document-text')
            ->url(fn (): string => route('sales.invoice', $this->saleRecord()), shouldOpenInNewTab: true);
    }

    private function openReprint(AuditLog $reprint, string $title): void
    {
        $url = route('pos.receipt.reprinted', ['sale' => $this->saleRecord(), 'reprint' => $reprint->getKey()]);

        $this->js('window.open('.json_encode($url.'?print=1').", '_blank', 'width=420,height=720')");

        Notification::make()
            ->title($title)
            ->actions([Action::make('open')->label('Open')->url($url.'?print=1', shouldOpenInNewTab: true)])
            ->success()
            ->send();
    }

    private function markReviewedAction(): Action
    {
        $canReview = fn (): bool => $this->user()->can('sales.review')
            && $this->saleRecord()->needs_review
            && $this->saleRecord()->reviewed_at === null;

        return Action::make('markReviewed')
            ->label('Mark reviewed')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible($canReview)
            ->authorize($canReview)
            ->requiresConfirmation()
            ->modalDescription('The sale stays exactly as recorded. If the price difference needs fixing, use a return or void instead.')
            ->action(function (Action $action): void {
                try {
                    app(OfflineSaleSyncService::class)->markReviewed($this->saleRecord(), $this->user());
                } catch (PosException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    $action->halt();
                }

                $this->refreshFormData(['reviewed_at']);
                Notification::make()->title('Sale marked as reviewed')->success()->send();
            });
    }

    private function voidAction(): Action
    {
        return Action::make('void')
            ->label('Void sale')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (): bool => $this->canVoid())
            ->authorize(fn (): bool => $this->canVoid())
            ->requiresConfirmation()
            ->modalDescription('The whole sale is cancelled and all its stock is put back. This cannot be undone.')
            ->schema([
                TextInput::make('reason')->required()->maxLength(255),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    app(CheckoutService::class)->void($this->saleRecord(), $this->user(), $data['reason']);
                } catch (PosException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    $action->halt();
                }

                $this->refreshFormData(['status', 'void_reason']);
                $url = route('pos.receipt.void', $this->saleRecord()).'?print=1';
                $this->js('window.open('.json_encode($url).", '_blank', 'width=420,height=720')");

                Notification::make()
                    ->title('Sale voided')
                    ->actions([Action::make('slip')->label('Print void slip')->url($url, shouldOpenInNewTab: true)])
                    ->success()
                    ->send();
            });
    }

    private function saleRecord(): Sale
    {
        /** @var Sale $sale */
        $sale = $this->getRecord();

        return $sale;
    }

    private function canVoid(): bool
    {
        return $this->user()->can('sales.void') && $this->saleRecord()->status === SaleStatus::Completed;
    }

    private function canReturn(): bool
    {
        return $this->user()->can('returns.create')
            && ! in_array($this->saleRecord()->status, [SaleStatus::Voided, SaleStatus::Returned], true);
    }

    /**
     * @return Collection<int, SaleItem>
     */
    private function returnableItems(): Collection
    {
        return $this->saleRecord()->items()->with(['batches', 'returnItems', 'variant.product.drugDetail'])->get()
            ->filter(fn (SaleItem $item): bool => Quantity::normalize((float) $item->quantity - (float) $item->returned_quantity) > 0);
    }
}
