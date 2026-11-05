<?php

namespace App\Filament\Resources\Devices;

use App\Filament\Resources\Devices\Pages\ManageDevices;
use App\Filament\Resources\PermissionResource;
use App\Filament\Support\ShopRules;
use App\Models\Device;
use App\Models\Location;
use App\Models\User;
use App\Services\TillOfflineService;
use App\Support\CurrentShop;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Tills (T1, T2 ...). Here the owner approves a till for offline sales, after
 * which it prepares itself (TillOfflineService), sees which tills hold a
 * device token, revokes it (that browser can no longer sync, and the till no
 * longer prepares itself), or resets it for a browser that lost its token.
 */
class DeviceResource extends PermissionResource
{
    protected static ?string $model = Device::class;

    protected static ?string $modelLabel = 'till';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->required()
                ->maxLength(10)
                ->regex('/^[A-Z0-9]+$/')
                ->helperText('Printed before every invoice number, e.g. T2-000001. Cannot be changed later.')
                ->disabledOn('edit')
                ->unique(ignoreRecord: true, modifyRuleUsing: ShopRules::limitToCurrentShop(...)),
            TextInput::make('name')->required()->maxLength(255),
            Select::make('location_id')
                ->label('Location')
                // A till sells, so only from a location that may sell.
                ->options(fn (): array => Location::query()->where('is_active', true)->where('is_sellable', true)->ordered()->pluck('name', 'id')->all())
                ->required(),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('A disabled till cannot sell or sync.'),
            // Pharmacy: the doctor's counter (docs/PHARMACY.md §9, branch 8).
            Toggle::make('opd_billing')
                ->label('OPD bills')
                ->default(false)
                ->helperText('Bills at this till are a consultation fee plus one Medicines amount.')
                ->visible(fn (): bool => self::pharmacy()),
            Toggle::make('offline_allowed')
                ->label('Offline sales allowed')
                ->default(false)
                ->helperText('The till prepares itself for offline sales. Turning this off stops its browser syncing.')
                ->visible(fn (): bool => self::offlineSync()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('location'))
            ->columns([
                TextColumn::make('code')->sortable()->weight('bold'),
                TextColumn::make('name')->wrap(),
                TextColumn::make('location.name')->label('Location')->wrap(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                IconColumn::make('opd_billing')->label('OPD bills')->boolean()->wrapHeader()->visible(fn (): bool => self::pharmacy()),
                IconColumn::make('offline_allowed')->label('Offline allowed')->boolean()->wrapHeader()->visible(fn (): bool => self::offlineSync()),
                TextColumn::make('tokens_count')->counts('tokens')->label('Offline devices')->wrapHeader(),
                TextColumn::make('tokens_max_last_used_at')->max('tokens', 'last_used_at')->label('Last synced')->since()->placeholder('Never')->wrapHeader(),
            ])
            ->defaultSort('code')
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('resetOffline')
                        ->label('Reset offline on this till')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->visible(fn (): bool => self::offlineSync())
                        ->authorize(fn (Device $record): bool => auth()->user()?->can('update', $record) ?? false)
                        ->requiresConfirmation()
                        ->modalDescription('For a till whose Chrome was reset or whose PC was replaced. Every browser registered as this till stops syncing, and the till sets itself up again the next time it is opened. Sales still waiting on an old browser stay there until that browser is this till again.')
                        ->action(function (Device $record): void {
                            $revoked = app(TillOfflineService::class)->reset($record, self::owner());

                            Notification::make()->title("{$record->code} reset: {$revoked} device token(s) revoked. It sets itself up the next time it is opened.")->success()->send();
                        }),
                    Action::make('revokeOfflineAccess')
                        ->label('Revoke offline access')
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->color('danger')
                        ->visible(fn (Device $record): bool => $record->tokens()->exists())
                        ->authorize(fn (Device $record): bool => auth()->user()?->can('update', $record) ?? false)
                        ->requiresConfirmation()
                        ->modalDescription('Every browser registered as this till stops syncing at once, and the till is no longer allowed offline. Sales it has not uploaded yet stay on that browser until the till is registered again.')
                        ->action(function (Device $record): void {
                            $revoked = app(TillOfflineService::class)->revoke($record, self::owner());

                            Notification::make()->title("Revoked {$revoked} device token(s) for {$record->code}")->success()->send();
                        }),
                ]),
            ]);
    }

    private static function pharmacy(): bool
    {
        return app(CurrentShop::class)->getOrFail()->hasModule('pharmacy');
    }

    private static function offlineSync(): bool
    {
        return app(CurrentShop::class)->getOrFail()->hasModule('offline_sync');
    }

    private static function owner(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDevices::route('/'),
        ];
    }
}
