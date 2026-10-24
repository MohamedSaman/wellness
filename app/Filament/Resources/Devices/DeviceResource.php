<?php

namespace App\Filament\Resources\Devices;

use App\Filament\Resources\Devices\Pages\ManageDevices;
use App\Filament\Resources\PermissionResource;
use App\Filament\Support\ShopRules;
use App\Models\Device;
use App\Models\Location;
use App\Support\CurrentShop;
use BackedEnum;
use Filament\Actions\Action;
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
 * Tills (T1, T2 ...). A till is registered for offline sales from the
 * checkout screen; here the owner sees which tills hold a device token and
 * can revoke it, after which that browser can no longer sync.
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
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('location'))
            ->columns([
                TextColumn::make('code')->sortable()->weight('bold'),
                TextColumn::make('name'),
                TextColumn::make('location.name')->label('Location'),
                IconColumn::make('is_active')->label('Active')->boolean(),
                IconColumn::make('opd_billing')->label('OPD bills')->boolean()->visible(fn (): bool => self::pharmacy()),
                TextColumn::make('tokens_count')->counts('tokens')->label('Offline devices'),
                TextColumn::make('tokens_max_last_used_at')->max('tokens', 'last_used_at')->label('Last synced')->since()->placeholder('Never'),
            ])
            ->defaultSort('code')
            ->recordActions([
                EditAction::make(),
                Action::make('revokeOfflineAccess')
                    ->label('Revoke offline access')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (Device $record): bool => $record->tokens()->exists())
                    ->authorize(fn (Device $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->requiresConfirmation()
                    ->modalDescription('Every browser registered as this till stops syncing at once. Sales it has not uploaded yet stay on that browser until the till is registered again.')
                    ->action(function (Device $record): void {
                        $revoked = $record->revokeSyncTokens();

                        Notification::make()->title("Revoked {$revoked} device token(s) for {$record->code}")->success()->send();
                    }),
            ]);
    }

    private static function pharmacy(): bool
    {
        return app(CurrentShop::class)->getOrFail()->hasModule('pharmacy');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDevices::route('/'),
        ];
    }
}
