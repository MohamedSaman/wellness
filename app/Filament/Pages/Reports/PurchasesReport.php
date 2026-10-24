<?php

namespace App\Filament\Pages\Reports;

use App\Models\Supplier;
use App\Support\CurrentShop;
use Filament\Forms\Components\Select;
use Livewire\Attributes\Url;

/**
 * Purchases (docs/PHARMACY.md §9, branch 11; purchasing): posted goods
 * received notes dated in the period by supplier and by month (value
 * before discounts, discounts, tax, which is part of the cost, the total
 * owed and the value of free goods), the notes themselves, and supplier
 * returns with their credit at batch cost. A reversing GRN is negative and
 * counts in the period it was made. With a location, what was received
 * there.
 */
class PurchasesReport extends SummaryReportPage
{
    protected static ?string $title = 'Purchases';

    protected static ?int $navigationSort = 30;

    #[Url]
    public ?string $supplier = null;

    protected static function isAvailable(): bool
    {
        return app(CurrentShop::class)->get()?->hasModule('purchasing') ?? false;
    }

    private function supplierId(): ?int
    {
        return ctype_digit((string) $this->supplier) && Supplier::query()->whereKey((int) $this->supplier)->exists() ? (int) $this->supplier : null;
    }

    protected function filterFields(): array
    {
        return [
            ...parent::filterFields(),
            Select::make('supplier')
                ->label('Supplier')
                ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])->all())
                ->placeholder('All suppliers')
                ->searchable()
                ->live(),
        ];
    }

    protected function documentFilters(): array
    {
        $id = $this->supplierId();

        return [['Supplier', $id === null ? 'All suppliers' : (string) Supplier::query()->whereKey($id)->value('name')]];
    }

    protected function tables(): array
    {
        $period = $this->period();
        $location = $this->locationId();
        $supplier = $this->supplierId();

        $columns = fn (string $label): array => [
            ReportColumn::text('name', $label),
            ReportColumn::integer('grns', 'GRNs'),
            ReportColumn::integer('reversals', 'Reversals'),
            ReportColumn::money('subtotal', 'Value'),
            ReportColumn::money('discounts', 'Discounts'),
            ReportColumn::money('tax', 'Tax (VAT)'),
            ReportColumn::money('total', 'Total'),
            ReportColumn::money('free_goods', 'Free goods value'),
        ];

        return [
            $this->makeTable('By supplier', $columns('Supplier'), $this->reports()->purchases($period, 'supplier', $location, $supplier), note: 'Total = value − discounts + tax: what is owed for the goods. Tax on supplier invoices is part of the cost. Free goods at the invoice\'s price, before discounts.'),
            $this->makeTable('By month', $columns('Month'), $this->reports()->purchases($period, 'month', $location, $supplier)),
            $this->makeTable('Goods received notes', [
                ReportColumn::text('grn', 'GRN'),
                ReportColumn::text('received_on', 'Received'),
                ReportColumn::text('supplier', 'Supplier'),
                ReportColumn::text('invoice', 'Invoice'),
                ...($this->multiLocation() ? [ReportColumn::text('location', 'Location')] : []),
                ReportColumn::money('subtotal', 'Value'),
                ReportColumn::money('discounts', 'Discounts'),
                ReportColumn::money('tax', 'Tax'),
                ReportColumn::money('total', 'Total'),
                ReportColumn::money('free_goods', 'Free goods'),
                ReportColumn::text('note', 'Note'),
            ], $this->reports()->goodsReceived($period, $location, $supplier)),
            $this->makeTable('Supplier returns', [
                ReportColumn::text('return_no', 'Return'),
                ReportColumn::text('returned_on', 'Returned'),
                ReportColumn::text('supplier', 'Supplier'),
                ...($this->multiLocation() ? [ReportColumn::text('location', 'Location')] : []),
                ReportColumn::text('reason', 'Reason'),
                ReportColumn::text('credit_note', 'Credit note'),
                ReportColumn::money('total', 'Credit (at batch cost)'),
            ], $this->reports()->supplierReturns($period, $location, $supplier)),
        ];
    }
}
