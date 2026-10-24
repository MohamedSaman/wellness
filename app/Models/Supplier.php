<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $name
 * @property string|null $contact_name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $tax_number
 * @property int|null $payment_terms_days
 * @property string|null $notes
 * @property bool $is_active
 *
 * Someone the shop buys from (suppliers module). Deactivated, never deleted:
 * orders, deliveries, returns and payments point at it. Changes go through
 * SupplierService and are audited.
 */
class Supplier extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payment_terms_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Active suppliers as id => name, for pickers.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return HasMany<PurchaseOrder, $this> */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** @return HasMany<GoodsReceipt, $this> */
    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /** @return HasMany<SupplierReturn, $this> */
    public function supplierReturns(): HasMany
    {
        return $this->hasMany(SupplierReturn::class);
    }

    /** @return HasMany<SupplierOpeningBalance, $this> */
    public function openingBalances(): HasMany
    {
        return $this->hasMany(SupplierOpeningBalance::class);
    }

    /** @return HasMany<SupplierPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    /** @return HasMany<SupplierPayable, $this> */
    public function payables(): HasMany
    {
        return $this->hasMany(SupplierPayable::class);
    }

    /** @return HasMany<SupplierCredit, $this> */
    public function credits(): HasMany
    {
        return $this->hasMany(SupplierCredit::class);
    }

    /** @return HasMany<SupplierAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierAllocation::class);
    }
}
