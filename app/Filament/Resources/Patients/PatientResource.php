<?php

namespace App\Filament\Resources\Patients;

use App\Filament\Resources\Patients\Actions\PatientQuickView;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\RelationManagers\ExchangesRelationManager;
use App\Filament\Resources\Patients\RelationManagers\SalesRelationManager;
use App\Filament\Resources\PermissionResource;
use App\Models\Location;
use App\Models\Patient;
use App\Models\Shift;
use App\Services\Data\PatientFilters;
use App\Services\PatientListService;
use App\Support\CurrentShop;
use App\Support\PatientAge;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The clinic's patients (docs/PHARMACY.md, branch 13): found by name, each
 * with their history across OPD and pharmacy bills at every location.
 * Added at the till; changed on the patient's page (patients.manage,
 * PatientService); never deleted. Health data: dispensing_register.view,
 * and every patient opened is written to the audit log.
 */
class PatientResource extends PermissionResource
{
    protected static ?string $model = Patient::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return (app(CurrentShop::class)->get()?->hasModule('pharmacy') ?? false) && parent::canAccess();
    }

    public static function table(Table $table): Table
    {
        $timezone = fn (): string => static::timezone();

        return static::listState($table)
            ->modifyQueryUsing(fn (Builder $query, HasTable $livewire): Builder => app(PatientListService::class)->apply($query, static::filtersOf($livewire)))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereIn('patients.id', static::searchPatients($search)))
                    ->sortable()
                    ->wrap(),
                TextColumn::make('age')
                    ->label('Age')
                    ->state(fn (Patient $record): ?string => PatientAge::format($record->ageMonths()))
                    ->placeholder('Not known'),
                TextColumn::make('visits')
                    ->label('Visits')
                    ->tooltip('Bills (not voided) matching the filters; all of them when nothing narrows the bills.')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(query: self::orderBy('visits')),
                TextColumn::make('sales_count')
                    ->label('Bills')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('first_visit_at')
                    ->label('First visit')
                    ->date('d/m/Y', $timezone)
                    ->placeholder('Never')
                    ->sortable(query: self::orderBy('first_visit_at')),
                TextColumn::make('last_visit_at')
                    ->label('Last visit')
                    ->dateTime('d/m/Y H:i', $timezone)
                    ->placeholder('Never')
                    ->sortable(query: self::orderBy('last_visit_at')),
                TextColumn::make('last_location')
                    ->label('Last location')
                    ->placeholder('-')
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->date('d/m/Y', $timezone)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // A row opens the patient's quick view over the list (branch 21); Open, their page.
            ->recordActions([
                PatientQuickView::make(),
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->iconButton()
                    ->color('gray')
                    ->url(fn (Patient $record): string => static::getUrl('view', ['record' => $record])),
            ])
            ->recordAction(PatientQuickView::NAME)
            ->recordUrl(null);
    }

    /** The shop's clock, for the list's times. */
    public static function timezone(): string
    {
        return app(CurrentShop::class)->getOrFail()->timezone ?: 'UTC';
    }

    /**
     * What the Patients and Visits tabs share (branch 21): the filters, the
     * search box, and the filters, search and sort kept in the user's session,
     * so the sidebar or a breadcrumb back to the list finds them as they were.
     */
    public static function listState(Table $table): Table
    {
        return $table
            ->searchable()
            ->filters(self::filters(), layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(['default' => 1, 'sm' => 2, 'xl' => 5])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->persistSortInSession();
    }

    /**
     * Patients whose name matches a search, for the search box of either tab.
     *
     * @return Builder<Patient>
     */
    public static function searchPatients(string $search): Builder
    {
        return Patient::query()->search($search)->select('patients.id');
    }

    /** Sorts on a column the list query selects (visits, first and last visit). */
    private static function orderBy(string $column): \Closure
    {
        return fn (Builder $query, string $direction): Builder => $query->orderBy($column, $direction === 'desc' ? 'desc' : 'asc');
    }

    /** The filters as the list applies them (and its exports). */
    public static function filtersOf(HasTable $livewire): PatientFilters
    {
        $state = property_exists($livewire, 'tableFilters') && is_array($livewire->tableFilters) ? $livewire->tableFilters : [];

        return PatientFilters::fromTableState(app(CurrentShop::class)->getOrFail(), $state);
    }

    /**
     * Every filter only holds its state: PatientListService applies them
     * together (one bill must match period, location, bill type and shift).
     *
     * @return list<Filter>
     */
    private static function filters(): array
    {
        $none = fn (Builder $query): Builder => $query;
        $multiLocation = fn (): bool => app(CurrentShop::class)->get()?->hasModule('multi_location') ?? false;

        return [
            Filter::make('last_visit')
                ->label('Last visit')
                ->schema([
                    Select::make('range')
                        ->label('Visited')
                        ->options(PatientFilters::PERIODS)
                        ->placeholder('Any time')
                        ->live(),
                    DatePicker::make('from')
                        ->label('From')
                        ->visible(fn (Get $get): bool => $get('range') === 'custom'),
                    DatePicker::make('to')
                        ->label('To')
                        ->visible(fn (Get $get): bool => $get('range') === 'custom'),
                ])
                ->query($none)
                ->indicateUsing(function (array $data): ?string {
                    $period = PatientFilters::period(app(CurrentShop::class)->getOrFail(), $data['range'] ?? null, $data['from'] ?? null, $data['to'] ?? null);

                    return $period === null ? null : 'Visited: '.(($data['range'] ?? null) === 'custom' ? $period->label() : PatientFilters::PERIODS[$data['range']].' ('.$period->label().')');
                }),
            Filter::make('location')
                ->schema([
                    Select::make('value')
                        ->label('Location visited')
                        ->options(fn (): array => Location::query()->where('is_active', true)->where('is_sellable', true)->ordered()->pluck('name', 'id')->all())
                        ->placeholder('Any location'),
                ])
                ->visible($multiLocation)
                ->query($none)
                ->indicateUsing(function (array $data): ?string {
                    $name = is_numeric($data['value'] ?? null) ? Location::query()->whereKey((int) $data['value'])->value('name') : null;

                    return $name === null ? null : 'Location: '.$name;
                }),
            Filter::make('bill_type')
                ->schema([
                    Select::make('value')
                        ->label('Bill type')
                        ->options(PatientFilters::BILL_TYPES)
                        ->placeholder('Any bill'),
                ])
                ->query($none)
                ->indicateUsing(fn (array $data): ?string => is_string($data['value'] ?? null) && isset(PatientFilters::BILL_TYPES[$data['value']]) ? 'Bill type: '.PatientFilters::BILL_TYPES[$data['value']] : null),
            Filter::make('visits')
                ->schema([
                    TextInput::make('min')
                        ->label('Visits at least')
                        ->integer()
                        ->minValue(1)
                        ->placeholder('e.g. 3')
                        ->helperText('In the period or shift when one is set; otherwise all time.'),
                ])
                ->query($none)
                ->indicateUsing(fn (array $data): ?string => is_numeric($data['min'] ?? null) && (int) $data['min'] >= 1 ? 'At least '.(int) $data['min'].' visits' : null),
            Filter::make('shift')
                ->schema([
                    Select::make('value')
                        ->label('Shift')
                        ->placeholder('Any shift')
                        ->searchable()
                        ->options(fn (): array => app(PatientListService::class)->shiftOptions())
                        ->getSearchResultsUsing(fn (string $search): array => app(PatientListService::class)->shiftOptions($search))
                        ->getOptionLabelUsing(fn ($value): ?string => static::shiftFilterLabel($value)),
                ])
                ->query($none)
                ->indicateUsing(fn (array $data): ?string => ($label = static::shiftFilterLabel($data['value'] ?? null)) === null ? null : 'Shift: '.$label),
        ];
    }

    /** "Open shifts now", or the shift's till, opening, cashier and state. */
    public static function shiftFilterLabel(mixed $value): ?string
    {
        if ($value === PatientFilters::OPEN_SHIFTS) {
            return 'Open shifts now';
        }

        $shift = is_numeric($value) ? Shift::query()->with(['device', 'user'])->find((int) $value) : null;

        return $shift === null ? null : app(PatientListService::class)->shiftLabel($shift);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Patient')->columns(['default' => 1, 'md' => 3])->schema([
                TextEntry::make('name')->label('Name'),
                TextEntry::make('age')
                    ->label('Age today')
                    ->state(fn (Patient $record): ?string => PatientAge::format($record->ageMonths()))
                    ->placeholder('Not known'),
                TextEntry::make('birth_date')
                    ->label('Date of birth')
                    ->date('d/m/Y')
                    ->suffix(fn (Patient $record): string => $record->birth_date_estimated ? ' (estimated from the age)' : '')
                    ->placeholder('Not known'),
                TextEntry::make('notes')->label('Notes')->placeholder('None')->columnSpanFull(),
                TextEntry::make('created_at')
                    ->label('Added')
                    ->dateTime('d/m/Y H:i', fn (): string => app(CurrentShop::class)->getOrFail()->timezone ?: 'UTC')
                    ->suffix(fn (Patient $record): string => collect([$record->device?->code, $record->creator?->name])->filter()->isEmpty() ? '' : ' at '.collect([$record->device?->code, $record->creator?->name])->filter()->implode(' by ')),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [SalesRelationManager::class, ExchangesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPatients::route('/'),
            'view' => ViewPatient::route('/{record}'),
        ];
    }
}
