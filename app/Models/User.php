<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property bool $is_active
 * @property int|null $shop_id
 * @property int|null $default_location_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Mirror the column default so freshly created instances (not yet
     * reloaded from the database) don't trip strict-mode missing attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'shop_id' => null,
        'default_location_id' => null,
    ];

    /**
     * The allowed location ids, read once per instance (one request); false = not read yet.
     *
     * @var list<int>|false|null
     */
    private array|false|null $usableLocationIdsCache = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** Where this user lands after signing in: owners run the shop, cashiers sell. */
    public function homePath(): string
    {
        return match (true) {
            $this->can('admin.access') => '/admin',
            $this->can('pos.checkout') => '/pos',
            default => '/dashboard',
        };
    }

    /**
     * The shop (tenant) this user works for.
     *
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Where this user usually works (multi_location): Stock In, transfers and
     * later the till start there. Not mass assignable; set by the owner.
     *
     * @return BelongsTo<Location, $this>
     */
    public function defaultLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'default_location_id');
    }

    /** The user's default location while it is active, otherwise the shop's default. */
    public function workLocation(Shop $shop): Location
    {
        $location = $this->default_location_id === null
            ? null
            : Location::withoutGlobalScope('shop')
                ->where('shop_id', $shop->getKey())
                ->where('is_active', true)
                ->find($this->default_location_id);

        return $location ?? $shop->defaultLocation();
    }

    /**
     * The locations this user may sell and use tills at (docs/PHARMACY.md §4.4).
     * None at all: see usableLocationIds(). Change them only through
     * LocationService::setAllowedLocations().
     *
     * @return BelongsToMany<Location, $this, LocationUser>
     */
    public function allowedLocations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'location_user')
            ->using(LocationUser::class)
            ->withTimestamps();
    }

    /**
     * The ids of the allowed locations stored for this user (location_user),
     * nothing implied: [] when none are set. What the user form shows.
     *
     * @return list<int>
     */
    public function allowedLocationIds(): array
    {
        // Not through the tenant scope: with no current shop it would read nothing.
        return array_values(LocationUser::withoutGlobalScope('shop')
            ->where('user_id', $this->getKey())
            ->orderBy('location_id')
            ->pluck('location_id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    /**
     * The ids of the locations this user may use, or null for every location:
     *   - allowed locations set: those, for every user (the owner included);
     *   - none set, in a shop with multi_location: every location for a user
     *     with locations.all (the owner), otherwise only the shop's default
     *     location;
     *   - none set without multi_location (a retail shop): every location.
     * Read once per instance; forgetUsableLocations() drops the cached list.
     *
     * @return list<int>|null
     */
    public function usableLocationIds(): ?array
    {
        if ($this->usableLocationIdsCache === false) {
            $ids = $this->allowedLocationIds();

            $this->usableLocationIdsCache = $ids !== [] ? $ids : $this->implicitLocationIds();
        }

        return $this->usableLocationIdsCache;
    }

    /**
     * Where a user with no allowed locations may work: null (every location)
     * without multi_location or with locations.all, otherwise the shop's default.
     *
     * @return list<int>|null
     */
    private function implicitLocationIds(): ?array
    {
        $shop = $this->shop_id === null ? null : Shop::query()->find($this->shop_id);

        if ($shop === null || ! $shop->hasModule('multi_location') || $this->can('locations.all')) {
            return null;
        }

        return [(int) $shop->defaultLocation()->getKey()];
    }

    /** Whether this user may sell and use tills at $location (usableLocationIds()). */
    public function mayUseLocation(Location|int $location): bool
    {
        $ids = $this->usableLocationIds();
        $id = $location instanceof Location ? (int) $location->getKey() : $location;

        return $ids === null || in_array($id, $ids, true);
    }

    /** Read the allowed locations again on the next check (after they changed). */
    public function forgetUsableLocations(): void
    {
        $this->usableLocationIdsCache = false;
    }

    /**
     * Who may open the admin panel (/admin): active shop users with
     * admin.access (the owner by default). Cashiers work at the till only.
     * Each screen inside the panel is further limited by permissions.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) ($this->getAttribute('is_active') ?? true)
            && $this->getAttribute('shop_id') !== null
            && $this->can('admin.access');
    }
}
