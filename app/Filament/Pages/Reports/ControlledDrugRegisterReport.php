<?php

namespace App\Filament\Pages\Reports;

use App\Enums\DrugSchedule;
use App\Models\ProductVariant;
use App\Services\ReportService;
use Filament\Forms\Components\Select;
use Livewire\Attributes\Url;

/**
 * The controlled-drug register (docs/PHARMACY.md §4.10, Q3): only when the
 * shop's register is switched on (Shop settings, controlled_register;
 * pharmacy). Every movement of a controlled medicine in the period, per
 * location and medicine, oldest first, from its balance brought forward:
 * date, patient, prescriber, batch, in, out and the running balance.
 */
class ControlledDrugRegisterReport extends ReportPage
{
    protected static ?string $title = 'Controlled-drug register';

    protected static ?int $navigationSort = 26;

    protected static bool $hasLocationFilter = true;

    /** The running balance reads down the page: kept in date order. */
    protected static bool $isSortable = false;

    #[Url]
    public ?string $medicine = null;

    protected static function isAvailable(): bool
    {
        return app(ReportService::class)->controlledRegisterEnabled();
    }

    private function medicineId(): ?int
    {
        return ctype_digit((string) $this->medicine) ? (int) $this->medicine : null;
    }

    /** @return array<int, string> variant id => name */
    private function medicines(): array
    {
        return ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->join('drug_details', 'drug_details.product_id', '=', 'products.id')
            ->where('drug_details.schedule', DrugSchedule::Controlled->value)
            ->orderBy('products.name')
            ->get(['product_variants.id', 'products.name'])
            ->mapWithKeys(fn ($variant): array => [(int) $variant->id => (string) $variant->name])
            ->all();
    }

    protected function filterFields(): array
    {
        return [
            ...parent::filterFields(),
            Select::make('medicine')->label('Medicine')->options(fn (): array => $this->medicines())->placeholder('Every controlled medicine')->searchable()->live(),
        ];
    }

    protected function documentFilters(): array
    {
        $id = $this->medicineId();

        return [['Medicine', $id === null ? 'Every controlled medicine' : ($this->medicines()[$id] ?? '')]];
    }

    protected function reportColumns(): array
    {
        return [
            ReportColumn::text('when', 'Date'),
            ...($this->multiLocation() ? [ReportColumn::text('location', 'Location')] : []),
            ReportColumn::text('medicine', 'Medicine'),
            ReportColumn::text('batch', 'Batch'),
            ReportColumn::text('document', 'Document'),
            ReportColumn::text('patient', 'Patient'),
            ReportColumn::text('prescriber', 'Prescriber'),
            ReportColumn::quantity('in', 'In', total: false),
            ReportColumn::quantity('out', 'Out', total: false),
            ReportColumn::quantity('balance', 'Balance', total: false),
            ReportColumn::quantity('batch_balance', 'Batch balance', total: false)->toggleable(hiddenByDefault: true),
        ];
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->controlledRegister($this->period(), $this->locationId(), $this->medicineId());
    }

    protected function documentNote(): ?string
    {
        return 'Quantities in base units. Balance: what the location holds of the medicine after each movement, its batches added up.';
    }
}
