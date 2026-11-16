<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Reports\Export\ReportDocument;
use App\Filament\Pages\Reports\Export\ReportTable;
use App\Filament\Resources\Doctors\Pages\ViewDoctor;
use App\Models\Device;
use App\Models\Doctor;
use App\Models\Shift;
use App\Models\User;
use App\Support\CurrentShop;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Livewire\Attributes\Url;

/**
 * Doctor payments (docs/PHARMACY.md, branch 18): each cash payment to a
 * doctor, newest first: when (shop time), the doctor (to their page), the
 * amount, the till, its location, the shift (to its summary), who paid and
 * the note; voided payments left out. Filters: the shop's days, the doctor,
 * the till, its location, who paid; Shift history's column opens one shift
 * (whatever the period). Totals per doctor above the table and in Excel and
 * PDF, the grand total under it. Pharmacy shops with cash movements.
 */
class DoctorPaymentsReport extends ReportPage
{
    protected static ?string $title = 'Doctor payments';

    protected static ?int $navigationSort = 30;

    protected static bool $hasLocationFilter = true;

    protected static bool $isSortable = false;

    #[Url]
    public ?string $doctor = null;

    #[Url]
    public ?string $till = null;

    #[Url]
    public ?string $user = null;

    /** One shift (from Shift history), by uuid. */
    #[Url]
    public ?string $shift = null;

    protected static function isAvailable(): bool
    {
        $shop = app(CurrentShop::class)->get();

        return ($shop?->hasModule('pharmacy') ?? false) && $shop->hasModule('cash_movements');
    }

    protected function locationLabel(): string
    {
        return 'Location';
    }

    /** @return array{doctor_id: int|null, device_id: int|null, user_id: int|null, shift_id: int|null} */
    public function paymentFilters(): array
    {
        return [
            'doctor_id' => ctype_digit((string) $this->doctor) ? (int) $this->doctor : null,
            'device_id' => ctype_digit((string) $this->till) ? (int) $this->till : null,
            'user_id' => ctype_digit((string) $this->user) ? (int) $this->user : null,
            'shift_id' => $this->chosenShift()?->getKey(),
        ];
    }

    private function chosenShift(): ?Shift
    {
        return filled($this->shift) ? Shift::query()->with('device')->where('uuid', (string) $this->shift)->first() : null;
    }

    public function getSubheading(): ?string
    {
        $totals = $this->reports()->doctorPaymentsByDoctor($this->period(), $this->locationId(), $this->paymentFilters());
        $shift = $this->chosenShift();
        $prefix = $shift === null ? '' : 'Shift '.$shift->device?->code.' opened '.$shift->opened_at->setTimezone($this->period()->timezone)->format('d/m/Y H:i').'. ';

        if ($totals === []) {
            return $prefix.'No doctor paid.';
        }

        $shop = app(CurrentShop::class)->getOrFail();

        return $prefix.collect($totals)->map(fn (array $row): string => $row['doctor'].' '.$shop->formatMoney($row['amount']).' ('.$row['payments'].')')->implode(' · ')
            .' · Total '.$shop->formatMoney(array_sum(array_column($totals, 'amount')));
    }

    protected function reportColumns(): array
    {
        return [
            ReportColumn::text('when', 'Date'),
            ReportColumn::text('doctor', 'Doctor'),
            ReportColumn::money('amount', 'Amount'),
            ReportColumn::text('till', 'Till'),
            ...($this->multiLocation() ? [ReportColumn::text('location', 'Location')] : []),
            ReportColumn::text('shift', 'Shift'),
            ReportColumn::text('paid_by', 'Paid by'),
            ReportColumn::text('note', 'Note'),
            ReportColumn::text('number', 'No.')->toggleable(hiddenByDefault: true),
        ];
    }

    protected function filterFields(): array
    {
        $changed = fn () => $this->filtersUpdated();

        return [
            ...parent::filterFields(),
            Select::make('doctor')->label('Doctor')->placeholder('Every doctor')->options(fn (): array => Doctor::query()->orderBy('name')->pluck('name', 'id')->all())->live()->afterStateUpdated($changed),
            Select::make('till')->label('Till')->placeholder('Every till')->options(fn (): array => $this->tills())->live()->afterStateUpdated($changed),
            Select::make('user')->label('Paid by')->placeholder('Everyone')->options(fn (): array => $this->users())->live()->afterStateUpdated($changed),
        ];
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
        $filters = $this->paymentFilters();
        $shift = $this->chosenShift();

        return [
            ['Doctor', $filters['doctor_id'] === null ? 'Every doctor' : (string) Doctor::query()->whereKey($filters['doctor_id'])->value('name')],
            ['Till', $filters['device_id'] === null ? 'Every till' : ($this->tills()[$filters['device_id']] ?? '')],
            ['Paid by', $filters['user_id'] === null ? 'Everyone' : ($this->users()[$filters['user_id']] ?? '')],
            ...($shift === null ? [] : [['Shift', $shift->device?->code.' opened '.$shift->opened_at->setTimezone($this->period()->timezone)->format('d/m/Y H:i')]]),
        ];
    }

    protected function configureColumn(ReportColumn $column, TextColumn $text): TextColumn
    {
        return match ($column->key) {
            'when', 'number' => $text->extraAttributes(['style' => 'white-space: nowrap']),
            'doctor' => $text
                ->url(fn (array $record): ?string => isset($record['doctor_id']) && ViewDoctor::canAccess() ? ViewDoctor::getUrl(['record' => $record['doctor_id']]) : null)
                ->color('primary')
                ->wrap(),
            'shift' => $text
                // The summary opens for its cashier or shifts.manage (ShiftSummaryService::mayRead()).
                ->url(fn (array $record): ?string => filled($record['shift_uuid'] ?? null) && (auth()->user()?->can('shifts.manage') ?? false) ? route('pos.shift.summary', ['shift' => $record['shift_uuid'], 'format' => 'a4']) : null, shouldOpenInNewTab: true)
                ->color('primary')
                ->tooltip('Open the shift\'s summary')
                ->extraAttributes(['style' => 'white-space: nowrap']),
            'note' => $text->wrap(),
            default => $text,
        };
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->doctorPaymentRows($this->period(), $this->locationId(), $this->paymentFilters());
    }

    /** The payments, then the totals per doctor (Excel, PDF, print). */
    public function document(): ReportDocument
    {
        $document = parent::document();
        $totals = $this->reports()->doctorPaymentsByDoctor($this->period(), $this->locationId(), $this->paymentFilters());

        return $this->makeDocument([
            ...$document->tables,
            new ReportTable('Totals per doctor', [
                ReportColumn::text('doctor', 'Doctor'),
                ReportColumn::integer('payments', 'Payments'),
                ReportColumn::money('amount', 'Paid'),
            ], $totals, $totals === [] ? null : [
                'doctor' => 'Total',
                'payments' => array_sum(array_column($totals, 'payments')),
                'amount' => array_sum(array_column($totals, 'amount')),
            ]),
        ]);
    }

    protected function documentNote(): ?string
    {
        return 'Cash paid to doctors from the tills; voided payments are not listed.';
    }
}
