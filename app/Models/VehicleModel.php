<?php

namespace App\Models;

use App\Enums\VehicleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Shared reference data (Bajaj Pulsar 150, Honda Dio, Bajaj RE 4S ...). */
class VehicleModel extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => VehicleType::class];
    }

    /** @return BelongsTo<VehicleMake, $this> */
    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'vehicle_make_id');
    }
}
