<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Shared reference data (Honda, Bajaj, Yamaha, Toyota ...). */
class VehicleMake extends Model
{
    protected $guarded = ['id'];

    /** @return HasMany<VehicleModel, $this> */
    public function models(): HasMany
    {
        return $this->hasMany(VehicleModel::class);
    }
}
