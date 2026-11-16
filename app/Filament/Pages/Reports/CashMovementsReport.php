<?php

namespace App\Filament\Pages\Reports;

use App\Enums\CashMovementType;
use App\Models\Device;
use App\Models\User;
use App\Support\CurrentShop;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Livewire\Attributes\Url;

/**
 * Cash movements & returns without receipt (docs/PHARMACY.md, branch 18):
 * every paid out, paid in and doctor payment, and every return without a
 * receipt, newest first, with what it did to its till's drawer (voided
 * movements are listed, marked, and move nothing). Filters: the shop's days,
 * the kind, the till, its location and who made it. CSV, Excel, PDF and
 * print, like every report. Shops with cash movements.
 */
class CashMovementsReport extends ReportPage
{
    protected static ?string $title = 'Cash movements & returns without receipt';

    protected static ?string $navigationLabel = 'Cash movements & no-receipt returns';

    protected static ?int $navigationSort = 29;

    protected static bool $hasLocationFilter = true;

    /** Newest first: the order is the point. */
    protected static bool $isSortable = false;

    #[Url]
    public ?string $type = null;

    #[Url]
    public ?string $till = null;

    #[Url]
    public ?string $user = null;

    protected static function isAvailable(): bool
    {
        return app(CurrentShop::class)->get()?->hasModule('cash_movements') ?? false;
    }

    protected function locationLabel(): string
    {
        return 'Location';
    }

    /** @return array{type: string|null, device_id: int|null, user_id: int|null} */
    public function movementFilters(): array
    {
        return [
            'type' => array_key_exists((string) $this->type, $this->types()) ? $this->type : null,
            'device_id' => ctype_digit((string) $this->till) ? (int) $this->till : null,
            'user_id' => ctype_digit((string) $this->user) ? (int) $this->user : null,
        ];
    }

    protected function reportColumns(): array
    {
        return [
            ReportColumn::text('when', 'Date'),
            ReportColumn::text('till', 'Till'),
            ...($this->multiLocation() ? [ReportColumn::text('location', 'Location')] : []),
            ReportColumn::text('shift', 'Shift'),
            ReportColumn::text('number', 'No.'),
            ReportColumn::text('type', 'Type'),
            ReportColumn::text('category', 'For / doctor / reason'),
            ReportColumn::money('amount', 'Amount', total: false),
            ReportColumn::money('drawer', 'Drawer'),
            ReportColumn::text('user', 'By'),
            ReportColumn::text('note', 'Note'),
            ReportColumn::text('items', 'Items returned'),
            ReportColumn::text('status', 'Voided'),
        ];
    }

    protected function filterFields(): array
    {
        $changed = fn () => $this->filtersUpdated();

        return [
            ...parent::filterFields(),
            Select::make('type')->label('Type')->placeholder('Every type')->options(fn (): array => $this->types())->live()->afterStateUpdated($changed),
            Select::make('till')->label('Till')->placeholder('Every till')->options(fn (): array => $this->tills())->live()->afterStateUpdated($changed),
            Select::make('user')->label('By')->placeholder('Everyone')->options(fn (): array => $this->users())->live()->afterStateUpdated($changed),
        ];
    }

    /** @return array<string, string> */
    private function types(): array
    {
        $pharmacy = $this->hasPharmacy();

        return [
            CashMovementType::PaidOut->value => CashMovementType::PaidOut->label(),
            CashMovementType::PaidIn->value => CashMovementType::PaidIn->label(),
            ...($pharmacy ? [
                CashMovementType::DoctorPayment->value => CashMovementType::DoctorPayment->label(),
                'no_receipt' => 'Return without receipt',
            ] : []),
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
        $filters = $this->movementFilters();

        return [
            ['Type', $filters['type'] === null ? 'Every type' : $this->types()[$filters['type']]],
            ['Till', $filters['device_id'] === null ? 'Every till' : ($this->tills()[$filters['device_id']] ?? '')],
            ['By', $filters['user_id'] === null ? 'Everyone' : ($this->users()[$filters['user_id']] ?? '')],
        ];
    }

    protected function configureColumn(ReportColumn $column, TextColumn $text): TextColumn
    {
        return match ($column->key) {
            'when', 'number' => $text->extraAttributes(['style' => 'white-space: nowrap']),
            'shift' => $text
                // The summary opens for its cashier or shifts.manage (ShiftSummaryService::mayRead()).
                ->url(fn (array $record): ?string => filled($record['shift_uuid'] ?? null) && (auth()->user()?->can('shifts.manage') ?? false) ? route('pos.shift.summary', ['shift' => $record['shift_uuid'], 'format' => 'a4']) : null, shouldOpenInNewTab: true)
                ->color('primary')
                ->tooltip('Open the shift\'s summary')
                ->extraAttributes(['style' => 'white-space: nowrap']),
            'category', 'note', 'items', 'status' => $text->wrap(),
            'drawer' => $text->color(fn ($state): ?string => $state !== null && (int) $state < 0 ? 'danger' : null),
            default => $text,
        };
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->cashMovementRows($this->period(), $this->locationId(), $this->movementFilters());
    }

    protected function documentNote(): ?string
    {
        return 'Drawer: what each one did to its till\'s expected cash (paid in +, paid out, doctor payments and cash refunds −). A voided movement is listed and moves nothing.';
    }
}
