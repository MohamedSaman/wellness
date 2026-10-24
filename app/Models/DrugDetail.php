<?php

namespace App\Models;

use App\Enums\DosageForm;
use App\Enums\DrugRoute;
use App\Enums\DrugSchedule;
use App\Enums\StorageCondition;
use App\Models\Concerns\BelongsToShop;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $product_id
 * @property string|null $brand_name
 * @property string|null $strength_value
 * @property string|null $strength_unit
 * @property DosageForm $dosage_form
 * @property DrugRoute|null $route
 * @property int|null $min_days_to_expiry overrides the shop's min_days_to_expiry_for_sale
 * @property DrugSchedule $schedule
 * @property StorageCondition|null $storage_condition
 * @property string|null $storage_note
 * @property string|null $registration_no
 *
 * What makes a product a medicine (docs/PHARMACY.md §4.1): brand name,
 * strength, dosage form, schedule, storage and NMRA registration number. One row per product, keyed
 * by product_id. The generic names are on product_generics; the
 * manufacturer is the product's brand.
 */
class DrugDetail extends Model
{
    use BelongsToShop;

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'strength_value' => 'decimal:3',
            'dosage_form' => DosageForm::class,
            'route' => DrugRoute::class,
            'min_days_to_expiry' => 'integer',
            'schedule' => DrugSchedule::class,
            'storage_condition' => StorageCondition::class,
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** "500 mg", "5 mg/5 ml", or null when no strength is recorded. */
    public function strength(): ?string
    {
        if ($this->strength_value === null) {
            return null;
        }

        return trim(Quantity::format((float) $this->strength_value).' '.$this->strength_unit);
    }
}
