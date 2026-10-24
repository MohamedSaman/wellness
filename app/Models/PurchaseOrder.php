<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $po_number
 * @property PurchaseOrderStatus $status
 * @property Carbon|null $expected_on
 * @property int $total expected, minor units
 * @property string|null $notes
 * @property Carbon|null $sent_at
 * @property Carbon|null $cancelled_at
 *
 * An order to a supplier (purchasing module, optional: goods can be received
 * without one). It never moves stock. Drafts are edited; a sent order is
 * received against (in one delivery or several) or cancelled. Changes go
 * through PurchaseOrderService; receiving updates it from GoodsReceiptService.
 */
class PurchaseOrder extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'expected_on' => 'date',
            'total' => 'integer',
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return HasMany<PurchaseOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<GoodsReceipt, $this> */
    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

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
