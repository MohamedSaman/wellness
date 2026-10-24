<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line in the stock ledger. Immutable: never updated or deleted.
 * Mistakes are corrected with a new adjustment movement.
 *
 * @property StockMovementType $type
 * @property string|null $reason_code AdjustmentReason or ReturnReason value, depending on the type
 * @property string|null $note
 * @property int|null $stock_batch_id the batch moved (batch-tracked items)
 * @property string|null $batch_balance_after
 * @property string|null $received_cost DECIMAL(20,4): the exact cost a receipt or transfer re-averaged with
 */
class StockMovement extends Model
{
    use BelongsToShop, HasPublicUuid;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'decimal:3',
            'balance_after' => 'decimal:3',
            'batch_balance_after' => 'decimal:3', // the batch's running balance at this location
            'received_cost' => 'decimal:4',       // exact cost a receipt or transfer re-averaged with
            'unit_cost' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /** "Damaged", "Wrong item" ...: the label of the stored reason code, if any. */
    public function reasonLabel(): ?string
    {
        return $this->type->reasonLabel($this->reason_code);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Stock movements are immutable.'));
        static::deleting(fn () => throw new \LogicException('Stock movements cannot be deleted.'));
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * The batch moved (batch-tracked items only).
     *
     * @return BelongsTo<StockBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
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

    /** The sale, return, purchase order ... that caused it. */
    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
