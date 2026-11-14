<?php

namespace App\Filament\Pages;

use App\Enums\BillType;
use App\Enums\SaleStatus;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\ShopMoney;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Services\PatientListService;
use App\Support\CurrentShop;
use App\Support\PatientAge;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Patients in a shift (docs/PHARMACY.md, branch 16): every bill with a
 * patient rung up in the shift, oldest first, with the time, the patient
 * (to their page), their age at the visit, the bill (to the sale), its
 * type, location and total; voided bills marked. Opened from Shift
 * history and from the Patients list's shift filter. Health data, so the
 * same permission as the patients' pages; pharmacy shops only. Read only.
 */
class ShiftPatients extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'shifts/{shift}/patients';

    protected static bool $shouldRegisterNavigation = false;

    public Shift $shift;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && (app(CurrentShop::class)->get()?->hasModule('pharmacy') ?? false)
            && $user->can('dispensing_register.view');
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'shifts.patients';
    }

    /** Bound by its uuid, tenant-scoped: another shop's shift is not found. */
    public function mount(Shift $shift): void
    {
        abort_unless(static::canAccess(), 403);

        $this->shift = $shift->load(['device', 'user']);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Patients in this shift';
    }

    public function getSubheading(): string
    {
        $timezone = app(CurrentShop::class)->getOrFail()->timezone ?: 'UTC';
        $label = app(PatientListService::class)->shiftLabel($this->shift);
        $closed = $this->shift->closed_at?->setTimezone($timezone)->format('d/m/Y H:i');
        $withoutPatient = app(PatientListService::class)->shiftBillsWithoutPatient($this->shift);

        return $label.($closed === null ? '' : ' at '.$closed)
            .($withoutPatient === 0 ? '' : ' · '.$withoutPatient.' '.($withoutPatient === 1 ? 'bill' : 'bills').' with no patient recorded');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('patients')
                ->label('Filter the patients list')
                ->icon(Heroicon::OutlinedFunnel)
                ->color('gray')
                ->url(fn (): string => PatientResource::getUrl('index', ['filters' => ['shift' => ['value' => (string) $this->shift->getKey()]]])),
        ];
    }

    public function table(Table $table): Table
    {
        $timezone = fn (): string => app(CurrentShop::class)->getOrFail()->timezone ?: 'UTC';

        return $table
            ->query(fn () => app(PatientListService::class)->shiftBillsQuery($this->shift))
            ->paginated([25, 50, 100])
            ->emptyStateHeading('No patients in this shift')
            ->columns([
                TextColumn::make('sold_at')->label('Time')->dateTime('d/m/Y H:i', $timezone),
                TextColumn::make('patient.name')
                    ->label('Patient')
                    ->wrap()
                    ->url(fn (Sale $record): ?string => $record->patient === null ? null : PatientResource::getUrl('view', ['record' => $record->patient]))
                    ->color('primary'),
                TextColumn::make('patient_age_months')
                    ->label('Age')
                    ->formatStateUsing(fn (?int $state): ?string => PatientAge::format($state))
                    ->placeholder('-'),
                TextColumn::make('invoice_no')
                    ->label('Bill')
                    ->url(fn (Sale $record): string => SaleResource::getUrl('view', ['record' => $record]))
                    ->color('primary')
                    ->extraAttributes(['style' => 'white-space: nowrap']),
                TextColumn::make('bill_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (BillType $state): string => $state === BillType::Opd ? 'OPD' : 'Pharmacy')
                    ->color(fn (BillType $state): string => $state === BillType::Opd ? 'info' : 'gray'),
                TextColumn::make('location.name')->label('Location')->wrap(),
                TextColumn::make('grand_total')->label('Total')->formatStateUsing(fn ($state): string => ShopMoney::format((int) $state))->alignEnd(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SaleStatus $state): string => ucwords(str_replace('_', ' ', $state->value)))
                    ->color(fn (SaleStatus $state): string => match ($state) {
                        SaleStatus::Completed => 'success',
                        SaleStatus::PartiallyReturned => 'warning',
                        default => 'danger',
                    }),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
