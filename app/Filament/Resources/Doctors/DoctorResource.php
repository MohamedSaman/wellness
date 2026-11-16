<?php

namespace App\Filament\Resources\Doctors;

use App\Enums\CashMovementType;
use App\Exceptions\PosException;
use App\Filament\Resources\Doctors\Pages\ManageDoctors;
use App\Filament\Resources\Doctors\Pages\ViewDoctor;
use App\Filament\Resources\Doctors\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\PermissionResource;
use App\Models\Doctor;
use App\Services\DoctorService;
use App\Support\CurrentShop;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Doctors (pharmacy, docs/PHARMACY.md branch 18): the doctors the clinic
 * pays in cash from the tills (Pay doctor). Add, edit, deactivate; open one
 * for their payment history. doctors.manage (the owner). Every change goes
 * through DoctorService.
 */
class DoctorResource extends PermissionResource
{
    protected static ?string $model = Doctor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 18;

    public static function canAccess(): bool
    {
        return (app(CurrentShop::class)->get()?->hasModule('pharmacy') ?? false) && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(150)->helperText('As it should print on the payment slip, e.g. Dr. S. Perera.'),
            TextInput::make('phone')->tel()->maxLength(50),
            Textarea::make('notes')->rows(2)->maxLength(1000)->columnSpanFull(),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('Only active doctors can be paid at the till. Their payments stay in the reports.'),
        ])->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('name')->weight('bold'),
                    TextEntry::make('phone')->placeholder('-'),
                    IconEntry::make('is_active')->label('Active')->boolean(),
                    TextEntry::make('notes')->placeholder('-'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $monthStart = CarbonImmutable::now(self::timezone())->startOfMonth()->utc();

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withSum(['payments as paid_this_month' => fn (Builder $payments) => $payments
                    ->where('type', CashMovementType::DoctorPayment->value)
                    ->whereNull('voided_at')
                    ->where('created_at', '>=', $monthStart)], 'amount')
                ->withMax(['payments as last_paid_at' => fn (Builder $payments) => $payments
                    ->where('type', CashMovementType::DoctorPayment->value)
                    ->whereNull('voided_at')], 'created_at'))
            ->columns([
                TextColumn::make('name')->weight('bold')->searchable()->sortable()->wrap(),
                TextColumn::make('phone')->toggleable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('paid_this_month')
                    ->label('Paid this month')
                    ->state(fn (Doctor $record): int => (int) ($record->getAttribute('paid_this_month') ?? 0))
                    ->formatStateUsing(fn (int $state): string => (string) app(CurrentShop::class)->getOrFail()->formatMoney($state))
                    ->alignEnd(),
                TextColumn::make('last_paid_at')
                    ->label('Last paid')
                    ->state(fn (Doctor $record): ?string => $record->getAttribute('last_paid_at') === null ? null : CarbonImmutable::parse((string) $record->getAttribute('last_paid_at'), 'UTC')->setTimezone(self::timezone())->format('d/m/Y H:i'))
                    ->placeholder('Never'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active')->default(true),
            ])
            ->defaultSort('name')
            ->recordUrl(fn (Doctor $record): string => ViewDoctor::getUrl(['record' => $record]))
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->label('Payments')->url(fn (Doctor $record): string => ViewDoctor::getUrl(['record' => $record])),
                    EditAction::make()
                        ->using(fn (Doctor $record, array $data, Action $action): Doctor => static::save($action, fn (): Doctor => app(DoctorService::class)->update($record, $data, static::user() ?? abort(403)))),
                ]),
            ]);
    }

    /**
     * Run a DoctorService call; a rule's failure is shown and the form stays open.
     *
     * @param  callable(): Doctor  $callback
     */
    public static function save(Action $action, callable $callback): Doctor
    {
        try {
            return $callback();
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            $action->halt();

            throw $exception;
        }
    }

    public static function timezone(): string
    {
        return (string) (app(CurrentShop::class)->getOrFail()->timezone ?: config('app.timezone'));
    }

    public static function getRelations(): array
    {
        return [PaymentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDoctors::route('/'),
            'view' => ViewDoctor::route('/{record}'),
        ];
    }
}
