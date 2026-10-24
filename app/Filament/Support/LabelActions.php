<?php

namespace App\Filament\Support;

use App\Exceptions\PosException;
use App\Filament\Pages\PrintLabels;
use App\Models\GoodsReceipt;
use App\Models\LabelPrint;
use App\Models\LabelTemplate;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockBatch;
use App\Models\User;
use App\Models\VariantUnit;
use App\Services\BarcodeService;
use App\Services\Data\LabelRequest;
use App\Services\Labels\LabelService;
use App\Support\CurrentShop;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Where labels are printed from (docs/PHARMACY.md §9, branch 7b): the
 * product page (each unit and pack, with a batch), a posted delivery (each
 * line in the pack it came in, as many as were received, with its batch),
 * the Batches screen (selected batches) and the product list (any
 * selection, e.g. the items without a manufacturer barcode). Each modal
 * either prints now (the print opens in a new tab as a preview) or adds
 * the labels to the user's print queue, to print with others in one go.
 *
 * Shown only with the labels module and labels.print; the service checks
 * both again.
 */
final class LabelActions
{
    public static function canPrint(): bool
    {
        $user = self::user();

        return $user !== null
            && (app(CurrentShop::class)->get()?->hasModule('labels') ?? false)
            && $user->can('labels.print');
    }

    /** The product page: labels of each unit and pack, quantity per line, optionally a batch. */
    public static function productAction(): Action
    {
        return self::modal(Action::make('printLabels'))
            ->modalDescription('How many labels of each unit and pack. A pack without a barcode gets an in-store barcode when its labels print.')
            ->fillForm(fn (Product $record): array => [
                'label_template_id' => self::service()->defaultTemplate()->getKey(),
                'lines' => self::rows(self::service()->forProduct($record)),
            ])
            ->schema(fn (Product $record): array => self::rowSchema(batches: $record->tracksBatches()))
            ->action(fn (array $data, array $arguments, Action $action, Product $record, Component $livewire) => self::submit(
                self::requestsFrom($data), $data, $arguments, 'product', mb_substr($record->name, 0, 60), $action, $livewire,
            ));
    }

    /** A posted delivery: each line in the pack it came in, as many labels as were received, with its batch. */
    public static function receiptAction(): Action
    {
        return self::modal(Action::make('printLabels'))
            ->label('Print labels for this delivery')
            ->visible(fn (GoodsReceipt $record): bool => self::canPrint() && $record->isPosted() && ! $record->isReversal())
            ->modalHeading(fn (GoodsReceipt $record): string => "Labels for {$record->grn_number}")
            ->modalDescription('One label for each pack received (free goods included), with its batch. Change the numbers for what you will stick on.')
            ->fillForm(fn (GoodsReceipt $record): array => [
                'label_template_id' => self::service()->defaultTemplate()->getKey(),
                'lines' => self::rows(self::service()->forReceipt($record)),
            ])
            ->schema(self::rowSchema(batches: false))
            ->action(fn (array $data, array $arguments, Action $action, GoodsReceipt $record, Component $livewire) => self::submit(
                self::requestsFrom($data), $data, $arguments, 'delivery', (string) $record->grn_number, $action, $livewire,
            ));
    }

    /** The Batches screen: labels for the selected batches, with their batch and expiry. */
    public static function batchesBulkAction(): BulkAction
    {
        return self::modal(BulkAction::make('printLabels'))
            ->modalDescription('Labels with each batch\'s number and expiry (printed when the template prints them).')
            ->fillForm(fn (): array => [
                'label_template_id' => self::service()->defaultTemplate()->getKey(),
                'unit' => 'pack',
                'copies' => 'one',
            ])
            ->schema([
                self::templateField(),
                Radio::make('unit')
                    ->label('Label')
                    ->options(['pack' => 'The largest pack (box, bottle)', 'base' => 'The unit stock is counted in'])
                    ->required(),
                Radio::make('copies')
                    ->label('How many')
                    ->options(['one' => 'One label for each batch', 'on_hand' => 'One for each of that unit on hand'])
                    ->required(),
            ])
            ->action(fn (Collection $records, array $data, array $arguments, BulkAction $action, Component $livewire) => self::submit(
                self::service()->forBatches(
                    $records->filter(fn ($batch): bool => $batch instanceof StockBatch)->all(),
                    (string) $data['unit'],
                    $data['copies'] === 'on_hand',
                ),
                $data, $arguments, 'batches', null, $action, $livewire,
            ))
            ->deselectRecordsAfterCompletion();
    }

    /** The product list: labels for any selection of products. */
    public static function productsBulkAction(): BulkAction
    {
        return self::modal(BulkAction::make('printLabels'))
            ->modalDescription('Packs without a barcode get an in-store barcode when their labels print.')
            ->fillForm(fn (): array => [
                'label_template_id' => self::service()->defaultTemplate()->getKey(),
                'units' => 'items',
                'quantity' => 1,
            ])
            ->schema([
                self::templateField(),
                Radio::make('units')
                    ->label('Labels for')
                    ->options([
                        'items' => 'Each item (the unit stock is counted in)',
                        'packs' => 'Each pack (box, strip, bottle)',
                        'all' => 'Each item and each pack',
                    ])
                    ->required(),
                TextInput::make('quantity')
                    ->label('Copies of each')
                    ->integer()
                    ->required()
                    ->minValue(1)
                    ->maxValue((int) config('pos.labels.max_copies', 1000)),
            ])
            ->action(fn (Collection $records, array $data, array $arguments, BulkAction $action, Component $livewire) => self::submit(
                self::service()->forProducts(
                    $records->filter(fn ($product): bool => $product instanceof Product)->all(),
                    (string) $data['units'],
                    (int) $data['quantity'],
                ),
                $data, $arguments, 'products', null, $action, $livewire,
            ))
            ->deselectRecordsAfterCompletion();
    }

    /**
     * The product list: give the selected items and their packs without a
     * barcode an in-store one (never replacing a manufacturer's barcode).
     */
    public static function giveBarcodesBulkAction(): BulkAction
    {
        $can = fn (): bool => (app(CurrentShop::class)->get()?->hasModule('labels') ?? false)
            && (self::user()?->can('products.manage') ?? false);

        return BulkAction::make('giveBarcodes')
            ->label('Give in-store barcodes')
            ->icon(Heroicon::OutlinedQrCode)
            ->visible($can)
            ->authorize($can)
            ->requiresConfirmation()
            ->modalDescription('Items and packs of the selected products that have no barcode get an in-store barcode (EAN-13 starting with 200 or 201). Barcodes already set are kept.')
            ->action(function (Collection $records): void {
                $variants = ProductVariant::query()->whereIn('product_id', $records->modelKeys())->get();

                try {
                    $given = app(BarcodeService::class)->assignMissing($variants, self::user() ?? abort(403));
                } catch (PosException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title($given === 0 ? 'Every item and pack already has a barcode' : $given.' '.Str::plural('barcode', $given).' given')
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Print now, or add to the queue ("Add to print queue" in the modal's
     * footer). A print opens in a new tab as its preview.
     *
     * @param  list<LabelRequest>  $requests
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $arguments
     */
    public static function submit(array $requests, array $data, array $arguments, string $source, ?string $sourceLabel, Action $action, Component $livewire): void
    {
        $user = self::user() ?? abort(403);
        $labels = self::service();

        try {
            if ($arguments['queue'] ?? false) {
                $added = $labels->addToQueue($user, $requests, $source, $sourceLabel);
                $queued = $labels->queuedLabels($user);

                Notification::make()
                    ->title($added.' '.Str::plural('label', $added).' added to your print queue')
                    ->body($queued.' '.Str::plural('label', $queued).' waiting to print.')
                    ->actions([Action::make('queue')->label('Open print queue')->url(PrintLabels::getUrl())])
                    ->success()
                    ->send();

                return;
            }

            $template = LabelTemplate::query()->find((int) ($data['label_template_id'] ?? 0)) ?? $labels->defaultTemplate();
            $print = $labels->print($user, $template, $requests, $source, $sourceLabel);
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
            $action->halt();

            return;
        }

        self::open($print, $livewire);
    }

    /** Open a print's preview in a new tab, with a link in case the browser blocks it. */
    public static function open(LabelPrint $print, Component $livewire): void
    {
        $url = self::printUrl($print);

        $livewire->js('window.open('.json_encode($url).", '_blank')");

        Notification::make()
            ->title($print->labels_count.' '.Str::plural('label', $print->labels_count).' ready to print')
            ->actions([Action::make('open')->label('Open print preview')->url($url, shouldOpenInNewTab: true)])
            ->success()
            ->send();
    }

    public static function printUrl(LabelPrint $print): string
    {
        return route('labels.print', ['labelPrint' => $print]);
    }

    /**
     * The modal every source shares: the template, then Print or Add to
     * print queue.
     *
     * @template T of Action
     *
     * @param  T  $action
     * @return T
     */
    private static function modal(Action $action): Action
    {
        return $action
            ->label('Print labels')
            ->icon(Heroicon::OutlinedTag)
            ->color('gray')
            ->visible(fn (): bool => self::canPrint())
            ->authorize(fn (): bool => self::canPrint())
            ->modalHeading('Print labels')
            ->modalSubmitActionLabel('Print')
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('queue', arguments: ['queue' => true])
                    ->label('Add to print queue')
                    ->color('gray'),
            ]);
    }

    private static function templateField(): Select
    {
        return Select::make('label_template_id')
            ->label('Template')
            ->options(fn (): array => self::service()->templateOptions())
            ->selectablePlaceholder(false)
            ->required()
            ->helperText('Used when printing now. The print queue picks its template when it prints.');
    }

    /**
     * The template and one row per label: what it is, its batch, copies.
     *
     * @return list<mixed>
     */
    private static function rowSchema(bool $batches): array
    {
        return [
            self::templateField(),
            Repeater::make('lines')
                ->label('Labels')
                ->addable(false)
                ->reorderable(false)
                ->deletable()
                ->columns(['default' => 1, 'md' => 12])
                ->schema([
                    Hidden::make('variant_id'),
                    Hidden::make('unit_id'),
                    Hidden::make('expiry'),
                    Hidden::make('label'),
                    ...($batches ? [] : [Hidden::make('stock_batch_id'), Hidden::make('batch_no')]),
                    TextEntry::make('what')
                        ->hiddenLabel()
                        ->state(fn (Get $get): ?string => $get('label'))
                        ->columnSpan(['default' => 1, 'md' => $batches ? 5 : 9]),
                    ...($batches ? [
                        Select::make('stock_batch_id')
                            ->label('Batch')
                            ->placeholder('No batch')
                            ->options(fn (Get $get): array => StockBatch::query()
                                ->where('product_variant_id', (int) $get('variant_id'))
                                ->where('is_unallocated', false)
                                ->where('quantity', '>', 0)
                                ->fefo()
                                ->get()
                                ->mapWithKeys(fn (StockBatch $batch): array => [(int) $batch->getKey() => $batch->label()])
                                ->all())
                            ->columnSpan(['default' => 1, 'md' => 4]),
                    ] : []),
                    TextInput::make('quantity')
                        ->label('Copies')
                        ->integer()
                        ->required()
                        ->minValue(0)
                        ->maxValue((int) config('pos.labels.max_copies', 1000))
                        ->columnSpan(['default' => 1, 'md' => 3]),
                ]),
        ];
    }

    /**
     * Repeater rows for requests, each with what it is: "Panadol 500 mg
     * Tablets · Box of 30 · 4790000000024 · B AB12 exp 03/2027".
     *
     * @param  list<LabelRequest>  $requests
     * @return array<string, array<string, mixed>>
     */
    private static function rows(array $requests): array
    {
        $variants = ProductVariant::query()->with(['product', 'units'])->whereKey(array_map(fn (LabelRequest $r): int => $r->variantId, $requests))->get()->keyBy('id');
        $rows = [];

        foreach ($requests as $i => $request) {
            $variant = $variants->get($request->variantId);

            if ($variant === null) {
                continue;
            }

            $unit = $request->unitId === null ? null : $variant->units->firstWhere('id', $request->unitId);
            $barcode = $unit === null ? $variant->barcode : $unit->barcode;
            $expiry = $request->expiry === null ? null : CarbonImmutable::parse($request->expiry)->format('m/Y');

            $rows["r{$i}"] = [
                'variant_id' => $request->variantId,
                'unit_id' => $request->unitId,
                'stock_batch_id' => $request->stockBatchId,
                'batch_no' => $request->batchNo,
                'expiry' => $request->expiry,
                'quantity' => $request->quantity,
                'label' => implode(' · ', array_filter([
                    $variant->product->name.(filled($variant->name) ? ' – '.$variant->name : ''),
                    $unit === null ? $variant->units->firstWhere('is_base', true)?->name : VariantUnit::describe($unit->name, $unit->factor),
                    $barcode ?? 'gets an in-store barcode',
                    $request->batchNo === null ? null : 'B '.$request->batchNo.($expiry === null ? '' : " exp {$expiry}"),
                ])),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<LabelRequest>
     */
    private static function requestsFrom(array $data): array
    {
        $requests = [];

        foreach ((array) ($data['lines'] ?? []) as $line) {
            $batchId = filled($line['stock_batch_id'] ?? null) ? (int) $line['stock_batch_id'] : null;

            $requests[] = new LabelRequest(
                variantId: (int) $line['variant_id'],
                unitId: filled($line['unit_id'] ?? null) ? (int) $line['unit_id'] : null,
                quantity: (int) ($line['quantity'] ?? 0),
                stockBatchId: $batchId,
                // A batch picked on the product page brings its own number and expiry.
                batchNo: $batchId === null || filled($line['batch_no'] ?? null) ? ($line['batch_no'] ?? null) : null,
                expiry: $batchId === null || filled($line['batch_no'] ?? null) ? ($line['expiry'] ?? null) : null,
            );
        }

        return $requests;
    }

    private static function service(): LabelService
    {
        return app(LabelService::class);
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
