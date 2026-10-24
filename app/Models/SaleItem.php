<?php

namespace App\Models;

use App\Enums\DrugSchedule;
use App\Enums\TaxCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property TaxCategory|null $tax_category
 * @property list<array{batch_id: int, quantity: float}>|null $till_batches an offline till's proposal, kept when the server changed it
 * @property int|null $list_price the price-list unit price when the line was charged something else (a price override, an OPD share)
 * @property bool $is_opd_medicine a medicine line of an OPD bill: its net is its share of the Medicines amount
 * @property DrugSchedule|null $drug_schedule the medicine's schedule when sold; null for anything else
 * @property string|null $prescriber
 * @property string|null $prescription_ref
 * @property int|null $prescription_id Phase 2 prescriptions; always null for now
 * @property string|null $strength the medicine's strength when sold ("500 mg"), for receipts
 * @property string|null $base_unit_name what stock is counted in when sold ("Tablet"), for receipts
 *
 * A line on a sale. Name, SKU, price and cost are copied at the moment of
 * sale so later product edits never change past receipts or profit reports.
 */
class SaleItem extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['unit_cost'];

    /**
     * Mirror the column defaults so a new instance reads them before a reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_opd_medicine' => false,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',          // in the sold unit
            'base_quantity' => 'decimal:3',     // quantity x unit_factor: what stock moved
            'unit_factor' => 'decimal:3',
            'returned_quantity' => 'decimal:3', // in the sold unit
            'unit_price' => 'integer',
            'unit_cost' => 'integer',
            'line_discount' => 'integer',
            'sale_discount_share' => 'integer',
            'tax_amount' => 'integer',
            'tax_category' => TaxCategory::class, // null on lines from before tax categories (standard)
            'line_total' => 'integer',
            'till_batches' => 'array',
            'list_price' => 'integer',
            'is_opd_medicine' => 'boolean',
            'drug_schedule' => DrugSchedule::class,
            'prescription_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /**
     * Where the line's stock came from; returns and voids go back there.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * The batches the line took (batch-tracked items), soonest expiry first.
     *
     * @return HasMany<SaleItemBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(SaleItemBatch::class)->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('id');
    }

    /**
     * What has come back of the line, one row per return.
     *
     * @return HasMany<SaleReturnItem, $this>
     */
    public function returnItems(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    /** unit_cost is per sold unit; stock goes back per base unit (a box's cost / 30). */
    public function unitCostPerBase(): int
    {
        $factor = (float) $this->unit_factor;

        return $factor > 0 ? (int) round($this->unit_cost / $factor) : $this->unit_cost;
    }

    /**
     * The unit sold; null on lines from before units (the base unit).
     *
     * @return BelongsTo<VariantUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(VariantUnit::class, 'variant_unit_id');
    }
}
