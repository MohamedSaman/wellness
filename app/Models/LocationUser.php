<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $location_id
 * @property int $user_id
 *
 * One of a user's allowed locations (docs/PHARMACY.md §4.4): a user with
 * any of these rows may sell and use tills only at those locations; a user
 * with none may use the shop's default location, or every location with
 * locations.all or without multi_location. Read through User::mayUseLocation();
 * written only by LocationService::setAllowedLocations().
 */
class LocationUser extends Pivot
{
    use BelongsToShop;

    protected $table = 'location_user';

    public $incrementing = true;

    /**
     * Narrower than Pivot's array<string>|bool: BelongsToShop appends
     * shop_id to this list.
     *
     * @var array<string>
     */
    protected $guarded = ['id'];

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
