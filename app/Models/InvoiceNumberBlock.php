<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A run of invoice numbers (e.g. T1-000201 to T1-000400) reserved by a till
 * while online. The till numbers its offline sales from it, and online sales
 * carry on after it, so the two can never produce the same number.
 */
class InvoiceNumberBlock extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'first_number' => 'integer',
            'last_number' => 'integer',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
