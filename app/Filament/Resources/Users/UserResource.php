<?php

namespace App\Filament\Resources\Users;

use App\Exceptions\PosException;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Location;
use App\Models\User;
use App\Services\LocationService;
use App\Support\CurrentShop;
use App\Support\PasswordRules;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

/**
 * Owner-only user management. Users are never deleted (their sales and stock
 * movements refer to them); they are disabled instead.
 */
class UserResource extends PermissionResource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 30;

    public static function getEloquentQuery(): Builder
    {
        // User is not a BelongsToShop model, so limit to the current shop here.
        return parent::getEloquentQuery()
            ->where('shop_id', app(CurrentShop::class)->getOrFail()->getKey())
            ->with(['roles', 'defaultLocation', 'allowedLocations']);
    }

    /** @return array<string, string> */
    public static function roleOptions(): array
    {
        return collect(array_keys(config('pos.roles')))
            ->mapWithKeys(fn ($role) => [(string) $role => ucfirst((string) $role)])
            ->all();
    }

    /** The role chosen on the form, else the user's own. */
    private static function roleOf(Get $get, ?User $record): ?string
    {
        $role = $get('role');

        return filled($role) ? (string) $role : $record?->getRoleNames()->first();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('password')
                ->password()
                ->revealable()
                // By the role chosen (App\Support\PasswordRules): in production 12+ characters for
                // admin users, 10+ for till-only users, never a password known from a breach.
                ->rule(fn (Get $get, ?User $record): Password => PasswordRules::forRole(self::roleOf($get, $record)))
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText(fn (string $operation, Get $get, ?User $record): string => PasswordRules::describe(PasswordRules::roleIsAdmin(self::roleOf($get, $record)))
                    .($operation === 'edit' ? ' Leave empty to keep the current password.' : '')),
            Select::make('role')
                ->options(static::roleOptions())
                // The password's rule and hint follow the role (PasswordRules).
                ->live()
                ->required()
                ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false)
                ->helperText(fn (?User $record): ?string => ($record?->is(auth()->user()) ?? false) ? 'You cannot change your own role.' : null),
            Select::make('default_location_id')
                ->label('Default location')
                ->options(fn (): array => Location::options())
                ->placeholder('The shop\'s default location')
                ->helperText('Where Stock In and transfers start for this user (and later the till).')
                ->visible(fn (): bool => app(CurrentShop::class)->getOrFail()->hasModule('multi_location')),
            // Which counters this user may sell at (docs/PHARMACY.md §4.4); none = the default
            // location, or every location for users with locations.all (the owner).
            Select::make('allowed_location_ids')
                ->label('Allowed locations')
                ->multiple()
                // Active locations, plus any the user has now (a deactivated one shows, so saving is refused rather than widening the list).
                ->options(fn (?User $record): array => Location::options() + ($record?->allowedLocations()->pluck('name', 'locations.id')->all() ?? []))
                ->placeholder(fn (): string => static::defaultLocationName().' only')
                ->helperText(fn (): string => 'Leave empty for '.static::defaultLocationName().' only. Users who may work everywhere (the owner) can use every location.')
                ->visible(fn (): bool => app(CurrentShop::class)->getOrFail()->hasModule('multi_location')),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false)
                ->helperText('Disabled users are signed out on their next request and cannot log in.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('role')
                    ->badge()
                    ->state(fn (User $record): ?string => $record->getRoleNames()->first())
                    ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
                TextColumn::make('defaultLocation.name')
                    ->label('Default location')
                    ->placeholder('Shop default')
                    ->visible(fn (): bool => app(CurrentShop::class)->getOrFail()->hasModule('multi_location')),
                TextColumn::make('allowed_locations')
                    ->label('Allowed locations')
                    ->state(fn (User $record): string => match (true) {
                        $record->allowedLocations->isNotEmpty() => $record->allowedLocations->sortBy('name')->pluck('name')->implode(', '),
                        $record->can('locations.all') => 'All',
                        default => static::defaultLocationName().' (default)',
                    })
                    ->toggleable()
                    ->visible(fn (): bool => app(CurrentShop::class)->getOrFail()->hasModule('multi_location')),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                Action::make('toggleActive')
                    ->label(fn (User $record): string => $record->is_active ? 'Disable' : 'Enable')
                    ->icon(fn (User $record) => $record->is_active ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheckCircle)
                    ->color(fn (User $record): string => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => (static::user()?->can('users.manage') ?? false) && ! $record->is(auth()->user()))
                    ->authorize(fn (): bool => (static::user()?->can('users.manage') ?? false))
                    ->action(function (User $record): void {
                        $record->forceFill(['is_active' => ! $record->is_active])->save();

                        Notification::make()->title($record->is_active ? 'User enabled' : 'User disabled')->success()->send();
                    }),
            ]);
    }

    /**
     * The default location from the form: one of this shop's active locations,
     * or null (the shop's default). Not mass assignable, so pages set it here.
     *
     * @param  array<string, mixed>  $data
     */
    public static function applyDefaultLocation(User $user, array $data): void
    {
        if (! array_key_exists('default_location_id', $data)) {
            return; // the field is hidden without multi_location: leave it as it is
        }

        $id = filled($data['default_location_id']) ? (int) $data['default_location_id'] : null;

        if ($id !== null && ! Location::query()->whereKey($id)->where('is_active', true)->exists()) {
            $id = null;
        }

        $user->forceFill(['default_location_id' => $id]);
    }

    /**
     * The allowed locations from the form, saved through LocationService
     * (users.manage, active locations, audited). Called after the user is
     * saved; the field is hidden without multi_location, and then nothing changes.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws PosException
     */
    public static function applyAllowedLocations(User $user, array $data): void
    {
        if (! array_key_exists('allowed_location_ids', $data)) {
            return;
        }

        $by = static::user();
        abort_unless($by instanceof User, 403);

        $ids = array_values(array_map(intval(...), array_filter((array) ($data['allowed_location_ids'] ?? []), filled(...))));

        app(LocationService::class)->setAllowedLocations($user, $ids, $by);
    }

    /** The shop's default location's name: where a user with no allowed locations works. */
    public static function defaultLocationName(): string
    {
        return app(CurrentShop::class)->getOrFail()->defaultLocation()->name;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
