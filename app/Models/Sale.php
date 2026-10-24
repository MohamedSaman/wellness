<?php

namespace App\Models;

use App\Enums\BillType;
use App\Enums\SaleStatus;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property SaleStatus $status
 * @property Carbon $sold_at the till's clock: what the receipt printed
 * @property Carbon|null $reported_at when reports count the sale: sold_at, unless an offline till's clock was out (docs/PHARMACY.md §7.5)
 * @property int|null $clock_offset_seconds offline: the till's clock minus the server's at upload (positive = the till was ahead)
 * @property BillType $bill_type
 * @property int|null $opd_medicines_amount an OPD bill's typed Medicines amount, minor units
 * @property int|null $opd_medicines_list_value an OPD bill's priced medicines at list price (Σ list price × quantity), minor units
 * @property bool $opd_below_list the Medicines amount was below the shop's warning share of the list value
 * @property Carbon|null $voided_at
 * @property bool $needs_review
 * @property list<array<string, mixed>>|null $review_reasons
 * @property Carbon|null $reviewed_at
 *
 * A completed checkout. All amounts are integers in minor units.
 * Sales are never deleted: they are voided or returned.
 */
class Sale extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    /**
     * Mirror the column defaults so a new instance reads them before a reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'bill_type' => 'standard',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'tax_total' => 'integer',
            'grand_total' => 'integer',
            'paid_total' => 'integer',
            'change_due' => 'integer',
            'refunded_total' => 'integer',
            'tax_rate' => 'decimal:2',
            'prices_include_tax' => 'boolean',
            'sold_at' => 'datetime',
            'voided_at' => 'datetime',
            'synced_at' => 'datetime',
            'needs_review' => 'boolean',
            'review_reasons' => 'array',
            'reviewed_at' => 'datetime',
            'bill_type' => BillType::class,
            'opd_medicines_amount' => 'integer',
            'opd_medicines_list_value' => 'integer',
            'opd_below_list' => 'boolean',
            'clock_offset_seconds' => 'integer',
            'reported_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Sales cannot be deleted. Void or return them instead.'));

        // Reports group by reported_at: the sale time itself unless offline sync says the till's clock was out.
        static::creating(function (Sale $sale): void {
            if ($sale->getAttribute('reported_at') === null) {
                $sale->setAttribute('reported_at', $sale->getAttribute('sold_at'));
            }
        });
    }

    /** @return HasMany<SaleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<SaleReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Offline sales flagged for the owner (price changed, invoice renumbered ...) and not yet checked.
     *
     * @param  Builder<Sale>  $query
     */
    public function scopeAwaitingReview(Builder $query): void
    {
        $query->where('needs_review', true)->whereNull('reviewed_at');
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
