<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Keeps a fast auto-increment `id` for joins, plus an ordered UUID (v7) in a
 * `uuid` column. The UUID is what tills create offline and what APIs/URLs
 * expose, so IDs created on different devices never clash and internal ids
 * are never leaked.
 */
trait HasPublicUuid
{
    use HasUuids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
