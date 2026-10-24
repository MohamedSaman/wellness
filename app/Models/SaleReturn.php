<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $sale_id
 * @property string $return_no
 * @property int $refund_total
 * @property PaymentMethod $refund_method
 * @property ReturnReason $reason_code
 * @property Carbon $returned_at
 * @property string|null $note
 * @property string|null $refund_reference the bank transfer or card reference of the refund
 *
 * A return of lines of a sale, and its refund.
 */
class SaleReturn extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'refund_total' => 'integer',
            'refund_method' => PaymentMethod::class,
            'reason_code' => ReturnReason::class,
            'returned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<SaleReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }
}
