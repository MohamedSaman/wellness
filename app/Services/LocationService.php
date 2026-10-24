<?php

namespace App\Services;

use App\Exceptions\PosException;
use App\Models\Device;
use App\Models\Location;
use App\Models\LocationUser;
use App\Models\StockLevel;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Support\CurrentShop;
use Illuminate\Support\Facades\DB;

/**
 * Stock locations (multi_location). The rules every screen goes through:
 *   - each shop has exactly one default location, and it is active;
 *   - codes are unique per shop (A-Z, 0-9 and dashes);
 *   - a location is deactivated, never deleted, and only once it holds no
 *     stock and no active till sells from it;
 *   - a till's location stays sellable.
 * Without multi_location a shop keeps its one location.
 *
 * Users' allowed locations (docs/PHARMACY.md §4.4): a user with some may use
 * only those; a user with none may use the shop's default location, or every
 * location with locations.all (the owner) or without multi_location
 * (User::usableLocationIds()).
 */
class LocationService
{
    public function __construct(private readonly CurrentShop $currentShop) {}

    /**
     * @param  array<string, mixed>  $data  name, code, address, is_default, is_active, is_sellable, sort_order
     */
    public function create(array $data, User $user): Location
    {
        $this->authorize($user);

        if (! $this->currentShop->getOrFail()->hasModule('multi_location')) {
            throw new PosException('Turn on the multi_location module to add locations.');
        }

        $attributes = $this->attributes($data);
        $this->guardCode($attributes['code']);

        return DB::transaction(function () use ($attributes): Location {
            $makeDefault = $attributes['is_default'];

            if ($makeDefault && ! $attributes['is_active']) {
                throw new PosException('The default location must be active.');
            }

            $location = Location::query()->create([...$attributes, 'is_default' => false]);

            if ($makeDefault) {
                $this->makeDefault($location);
            }

            return $location;
        });
    }

    /**
     * @param  array<string, mixed>  $data  name, code, address, is_default, is_active, is_sellable, sort_order
     */
    public function update(Location $location, array $data, User $user): Location
    {
        $this->authorize($user);

        $attributes = $this->attributes($data);
        $this->guardCode($attributes['code'], $location);

        return DB::transaction(function () use ($location, $attributes): Location {
            /** @var Location $location */
            $location = Location::query()->whereKey($location->getKey())->lockForUpdate()->firstOrFail();

            if ($location->is_default && ! $attributes['is_default']) {
                throw new PosException('Make another location the default instead; a shop always has one.');
            }

            if (! $attributes['is_active']) {
                $this->guardDeactivation($location, $attributes['is_default']);
            }

            if (! $attributes['is_sellable'] && ($till = $this->activeTillAt($location)) !== null) {
                throw new PosException("Till {$till->code} sells from {$location->name}, so it must stay sellable. Move the till first.");
            }

            $makeDefault = $attributes['is_default'] && ! $location->is_default;
            $location->update([...$attributes, 'is_default' => $location->is_default]);

            if ($makeDefault) {
                $this->makeDefault($location);
            }

            return $location;
        });
    }

    /**
     * Replace the locations $user may sell and use tills at. An empty list
     * clears them: the shop's default location only, or every location for a
     * user with locations.all (User::usableLocationIds()). Needs users.manage; every id must
     * be an active location of the shop. One audit entry,
     * allowed_locations_changed, with the old and new ids.
     *
     * @param  list<int>  $locationIds
     */
    public function setAllowedLocations(User $user, array $locationIds, User $by): void
    {
        if (! $by->can('users.manage')) {
            throw new PosException('You do not have permission to manage users.');
        }

        $shop = $this->currentShop->getOrFail();

        if ((int) $user->shop_id !== (int) $shop->getKey()) {
            throw new PosException('Choose a user of this shop.');
        }

        $ids = array_values(array_unique(array_map(intval(...), $locationIds)));
        sort($ids);

        $active = Location::query()->whereKey($ids)->where('is_active', true)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if (count($active) !== count($ids)) {
            throw new PosException('Choose active locations of this shop.');
        }

        DB::transaction(function () use ($user, $ids, $by): void {
            $old = LocationUser::query()->where('user_id', $user->getKey())->lockForUpdate()
                ->orderBy('location_id')->pluck('location_id')->map(fn ($id): int => (int) $id)->all();

            if ($old === $ids) {
                return;
            }

            LocationUser::query()->where('user_id', $user->getKey())->whereNotIn('location_id', $ids)->delete();

            foreach (array_diff($ids, $old) as $locationId) {
                LocationUser::query()->create(['user_id' => $user->getKey(), 'location_id' => $locationId]);
            }

            app(AuditObserver::class)->record('allowed_locations_changed', $user, ['location_ids' => $old], ['location_ids' => $ids], (int) $by->getKey());
        });

        $user->forgetUsableLocations();
    }

    /** The one default: this location, and no other in the shop. */
    private function makeDefault(Location $location): void
    {
        Location::query()->where('is_default', true)->whereKeyNot($location->getKey())->lockForUpdate()->get()
            ->each(fn (Location $other) => $other->update(['is_default' => false]));

        $location->update(['is_default' => true]);
    }

    private function guardDeactivation(Location $location, bool $isDefault): void
    {
        if ($isDefault) {
            throw new PosException('The default location must be active.');
        }

        if (($till = $this->activeTillAt($location)) !== null) {
            throw new PosException("Till {$till->code} sells from {$location->name}. Move or disable the till first.");
        }

        $holding = StockLevel::query()->where('location_id', $location->getKey())->where('quantity', '!=', 0)->count();

        if ($holding > 0) {
            throw new PosException("{$location->name} still holds stock of {$holding} item(s). Transfer or count it out first.");
        }
    }

    private function activeTillAt(Location $location): ?Device
    {
        return Device::query()->where('location_id', $location->getKey())->where('is_active', true)->orderBy('code')->first();
    }

    private function guardCode(string $code, ?Location $ignore = null): void
    {
        $taken = Location::query()
            ->where('code', $code)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore?->getKey()))
            ->exists();

        if ($taken) {
            throw new PosException("Another location already has the code {$code}.");
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, code: string, address: string|null, is_default: bool, is_active: bool, is_sellable: bool, sort_order: int}
     */
    private function attributes(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $code = strtoupper(trim((string) ($data['code'] ?? '')));

        if ($name === '') {
            throw new PosException('A location needs a name.');
        }

        if (preg_match('/^[A-Z0-9-]{1,20}$/', $code) !== 1) {
            throw new PosException('A location code is 1 to 20 letters, digits or dashes, e.g. GEN.');
        }

        return [
            'name' => mb_substr($name, 0, 255),
            'code' => $code,
            'address' => filled($data['address'] ?? null) ? trim((string) $data['address']) : null,
            'is_default' => (bool) ($data['is_default'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'is_sellable' => (bool) ($data['is_sellable'] ?? true),
            'sort_order' => max(0, (int) ($data['sort_order'] ?? 0)),
        ];
    }

    private function authorize(User $user): void
    {
        if (! $user->can('locations.manage')) {
            throw new PosException('You do not have permission to manage locations.');
        }
    }
}
