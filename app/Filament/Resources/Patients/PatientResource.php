<?php

namespace App\Filament\Resources\Patients;

use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\RelationManagers\SalesRelationManager;
use App\Filament\Resources\PermissionResource;
use App\Models\Patient;
use App\Support\CurrentShop;
use App\Support\PatientAge;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
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
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount('sales')
                ->withMax('sales as last_visit_at', 'sold_at'))
            ->defaultSort('name')
            ->searchable()
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereIn('patients.id', Patient::query()->search($search)->select('patients.id')))
                    ->sortable()
                    ->wrap(),
                TextColumn::make('age')
                    ->label('Age')
                    ->state(fn (Patient $record): ?string => PatientAge::format($record->ageMonths()))
                    ->placeholder('Not known'),
                TextColumn::make('sales_count')
                    ->label('Bills')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('last_visit_at')
                    ->label('Last visit')
                    ->dateTime('d/m/Y H:i', fn (): string => app(CurrentShop::class)->getOrFail()->timezone ?: 'UTC')
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->date('d/m/Y', fn (): string => app(CurrentShop::class)->getOrFail()->timezone ?: 'UTC')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(fn (Patient $record): string => static::getUrl('view', ['record' => $record]));
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
        return [SalesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPatients::route('/'),
            'view' => ViewPatient::route('/{record}'),
        ];
    }
}
