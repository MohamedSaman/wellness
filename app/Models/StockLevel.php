<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $quantity
 * @property string $average_cost DECIMAL(20,4) minor units per base unit (docs/PHARMACY.md Q9)
 * @property string|null $last_cost DECIMAL(20,4) minor units per base unit
 * @property string|null $reorder_level overrides the variant's at this location
 * @property string|null $par_level what reorder suggestions top this location up to
 *
 * Current on-hand quantity per variant per location, and the weighted average
 * cost of that stock at that location. A fast cache of the stock_movements
 * ledger, only ever changed by StockService inside the same transaction as
 * the movement. Rebuild quantities with `php artisan pos:stock-rebuild`.
 */
class StockLevel extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    /** Costs are sensitive: hidden from JSON unless explicitly shown. */
    protected $hidden = ['average_cost', 'last_cost'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'average_cost' => 'decimal:4',
            'last_cost' => 'decimal:4',
            'reorder_level' => 'decimal:3',
            'par_level' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
