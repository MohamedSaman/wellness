<?php

namespace App\Models;

use App\Enums\ReturnReason;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $uuid the till's: a retry returns this exchange
 * @property string $number EX-000001
 * @property int $shift_id the till's open shift: the drawer the cash came out of
 * @property int $device_id
 * @property int $location_id the till's
 * @property int $user_id
 * @property int|null $patient_id
 * @property int|null $doctor_id
 * @property ReturnReason $reason
 * @property string|null $note
 * @property int|null $original_sale_id
 * @property int|null $sale_return_id
 * @property int|null $no_receipt_return_id
 * @property int|null $new_sale_id null: a plain return
 * @property int $credit_total the return's value
 * @property int $credit_applied the part that paid the new bill (payment method "exchange")
 * @property int $new_total the new bill's total
 * @property int $patient_paid new bill − credit applied
 * @property int $cash_refunded credit − credit applied, from the till's drawer
 * @property Carbon $exchanged_at
 *
 * A return or an exchange at the till (docs/PHARMACY.md, branch 19): items
 * of a bill (or, by the owner, without one) taken back as credit, and
 * the new items the doctor gave instead paid with it, in one transaction
 * and on one EXCHANGE receipt. Never edited or deleted.
 */
class Exchange extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason' => ReturnReason::class,
            'credit_total' => 'integer',
            'credit_applied' => 'integer',
            'new_total' => 'integer',
            'patient_paid' => 'integer',
            'cash_refunded' => 'integer',
            'exchanged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Exchanges are never deleted.'));
    }

    public function isPlainReturn(): bool
    {
        return $this->new_sale_id === null;
    }

    /** @return BelongsTo<Sale, $this> */
    public function originalSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'original_sale_id');
    }

    /** @return BelongsTo<Sale, $this> */
    public function newSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'new_sale_id');
    }

    /** @return BelongsTo<SaleReturn, $this> */
    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    /** @return BelongsTo<NoReceiptReturn, $this> */
    public function noReceiptReturn(): BelongsTo
    {
        return $this->belongsTo(NoReceiptReturn::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
