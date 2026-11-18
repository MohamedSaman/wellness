<?php

namespace App\Filament\Pages\Reports;

use App\Enums\ReturnReason;
use App\Filament\Resources\Sales\SaleResource;
use App\Models\Device;
use App\Models\Patient;
use App\Models\Sale;
use App\Models\User;
use App\Support\CurrentShop;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Livewire\Attributes\Url;

/**
 * Exchanges & returns at the till (docs/PHARMACY.md, branch 19): every
 * return and exchange, newest first, on the shop's days: the bill the items
 * came from, what came back and where it went, the new bill and its items,
 * the credit, what the patient paid on top and the cash refunded from the
 * drawer, the reason, doctor and cashier. Filters: patient, cashier, reason,
 * till, location. CSV, Excel, PDF and print, like every report. Pharmacy.
 */
class ExchangesReport extends ReportPage
{
    protected static ?string $title = 'Exchanges & returns';

    protected static ?string $navigationLabel = 'Exchanges & returns';

    protected static ?int $navigationSort = 30;

    protected static bool $hasLocationFilter = true;

    /** Newest first: the order is the point. */
    protected static bool $isSortable = false;

    #[Url]
    public ?string $till = null;

    #[Url]
    public ?string $user = null;

    #[Url]
    public ?string $patient = null;

    #[Url]
    public ?string $reason = null;

    protected static function isAvailable(): bool
    {
        return app(CurrentShop::class)->get()?->hasModule('pharmacy') ?? false;
    }

    protected function locationLabel(): string
    {
        return 'Location';
    }

    /** @return array{device_id: int|null, user_id: int|null, patient_id: int|null, reason: string|null} */
    public function exchangeFilters(): array
    {
        return [
            'device_id' => ctype_digit((string) $this->till) ? (int) $this->till : null,
            'user_id' => ctype_digit((string) $this->user) ? (int) $this->user : null,
            'patient_id' => ctype_digit((string) $this->patient) ? (int) $this->patient : null,
            'reason' => array_key_exists((string) $this->reason, ReturnReason::tillOptions()) ? $this->reason : null,
        ];
    }

    protected function reportColumns(): array
    {
        return [
            ReportColumn::text('when', 'Date'),
            ReportColumn::text('number', 'No.'),
            ReportColumn::text('kind', 'Kind'),
            ReportColumn::text('till', 'Till'),
            ...($this->multiLocation() ? [ReportColumn::text('location', 'Location')] : []),
            ReportColumn::text('patient', 'Patient'),
            ReportColumn::text('original', 'From bill'),
            ReportColumn::text('returned', 'Returned'),
            ReportColumn::text('new_bill', 'New bill'),
            ReportColumn::text('new_items', 'Given instead'),
            ReportColumn::money('credit', 'Credit'),
            ReportColumn::money('new_total', 'New items'),
            ReportColumn::money('patient_paid', 'Patient paid'),
            ReportColumn::money('refunded', 'Refunded'),
            ReportColumn::text('reason', 'Reason'),
            ReportColumn::text('doctor', 'Doctor'),
            ReportColumn::text('user', 'Cashier'),
            ReportColumn::text('note', 'Note'),
        ];
    }

    protected function filterFields(): array
    {
        $changed = fn () => $this->filtersUpdated();

        return [
            ...parent::filterFields(),
            Select::make('patient')->label('Patient')->placeholder('Every patient')->options(fn (): array => $this->patients())->searchable()->live()->afterStateUpdated($changed),
            Select::make('user')->label('Cashier')->placeholder('Everyone')->options(fn (): array => $this->users())->live()->afterStateUpdated($changed),
            Select::make('reason')->label('Reason')->placeholder('Every reason')->options(ReturnReason::tillOptions())->live()->afterStateUpdated($changed),
            Select::make('till')->label('Till')->placeholder('Every till')->options(fn (): array => $this->tills())->live()->afterStateUpdated($changed),
        ];
    }

    /** @return array<int, string> the patients with a return or exchange */
    private function patients(): array
    {
        return Patient::query()->whereHas('exchanges')->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    private function tills(): array
    {
        return Device::query()->orderBy('code')->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Device $device): array => [(int) $device->getKey() => trim($device->code.' '.$device->name)])->all();
    }

    /** @return array<int, string> */
    private function users(): array
    {
        return User::query()->where('shop_id', app(CurrentShop::class)->getOrFail()->getKey())->orderBy('name')->pluck('name', 'id')->all();
    }

    protected function documentFilters(): array
    {
        $filters = $this->exchangeFilters();

        return [
            ['Patient', $filters['patient_id'] === null ? 'Every patient' : ($this->patients()[$filters['patient_id']] ?? '')],
            ['Cashier', $filters['user_id'] === null ? 'Everyone' : ($this->users()[$filters['user_id']] ?? '')],
            ['Reason', $filters['reason'] === null ? 'Every reason' : ReturnReason::tillOptions()[$filters['reason']]],
            ['Till', $filters['device_id'] === null ? 'Every till' : ($this->tills()[$filters['device_id']] ?? '')],
        ];
    }

    protected function configureColumn(ReportColumn $column, TextColumn $text): TextColumn
    {
        $bill = fn (?string $uuid): ?string => $uuid === null || ($sale = Sale::query()->where('uuid', $uuid)->first()) === null ? null : SaleResource::getUrl('view', ['record' => $sale]);

        return match ($column->key) {
            'when', 'number' => $text->extraAttributes(['style' => 'white-space: nowrap']),
            'original' => $text->url(fn (array $record): ?string => $bill($record['original_uuid'] ?? null))->color('primary'),
            'new_bill' => $text->url(fn (array $record): ?string => $bill($record['new_bill_uuid'] ?? null))->color('primary'),
            'returned', 'new_items', 'note', 'reason' => $text->wrap(),
            default => $text,
        };
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->exchangeRows($this->period(), $this->locationId(), $this->exchangeFilters());
    }

    protected function documentNote(): ?string
    {
        return 'Credit: what came back is worth. It pays the new items (Exchange credit); the patient pays what is left, or the rest is refunded in cash from the till\'s drawer. The drawer moves by Patient paid − Refunded only.';
    }
}
