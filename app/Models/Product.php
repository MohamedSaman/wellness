<?php

namespace App\Models;

use App\Enums\TaxCategory;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property TaxCategory $tax_category
 * @property bool $needs_review created while receiving by someone who may not manage products: not sellable until reviewed
 * @property bool $created_while_receiving
 * @property Carbon|null $reviewed_at
 * @property bool $allow_price_override the till may type its price (allowsPriceOverride(): a pharmacy shop's non-medicine, else a service)
 * @property bool $ask_price_at_till the till opens the price box as soon as the item is added (pharmacy)
 *
 * The thing customers recognise ("Vega Off-Road Helmet", "Brake Pad Set").
 * What is actually sold and stocked is a ProductVariant (size/colour/etc).
 * Products without options simply have one variant.
 */
class Product extends Model
{
    use BelongsToShop, HasPublicUuid, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * Mirror the column default so a new instance reads it before a reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'allow_price_override' => false,
        'ask_price_at_till' => false,
    ];

    protected function casts(): array
    {
        return [
            'oem_numbers' => 'array',
            'track_stock' => 'boolean',
            'track_batches' => 'boolean', // batch and expiry stock, FEFO (every medicine)
            'whole_units_only' => 'boolean', // no half tablets: quantities are whole base units
            'tax_category' => TaxCategory::class,
            'is_active' => 'boolean',
            'needs_review' => 'boolean',
            'created_while_receiving' => 'boolean',
            'reviewed_at' => 'datetime',
            'allow_price_override' => 'boolean',
            'ask_price_at_till' => 'boolean',
        ];
    }

    /**
     * Whether the till may type this product's price (docs/PHARMACY.md §4.3 and
     * branch 14): the product allows it and, in a pharmacy shop, is not a
     * medicine (a dressing, a lab test, a procedure, the consultation fee);
     * in any other shop it holds no stock (a service such as fitting), as
     * before. The variant's till_price_min / till_price_max bound the price.
     */
    public function allowsPriceOverride(): bool
    {
        if (! $this->allow_price_override) {
            return false;
        }

        if (self::pharmacyRules()) {
            return ! $this->isMedicine();
        }

        // Raw attributes: a product created without track_stock (default true) has none loaded yet.
        return ! (bool) ($this->getAttributes()['track_stock'] ?? true);
    }

    /** Whether the till asks for this product's price as soon as it is added (pharmacy, branch 14). */
    public function asksPriceAtTill(): bool
    {
        return self::pharmacyRules() && (bool) $this->ask_price_at_till && $this->allowsPriceOverride();
    }

    /**
     * The pharmacy's rules for prices typed at the till (branch 14): any
     * non-medicine, with limits. A shop without the module keeps the rules it
     * had (services only, no limits required).
     */
    public static function pharmacyRules(): bool
    {
        return app(CurrentShop::class)->get()?->hasModule('pharmacy') ?? false;
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * The goods received note or purchase order the item was created on.
     *
     * @return MorphTo<Model, $this>
     */
    public function createdOn(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * Medicine details; null for anything that is not a medicine.
     *
     * @return HasOne<DrugDetail, $this>
     */
    public function drugDetail(): HasOne
    {
        return $this->hasOne(DrugDetail::class);
    }

    /**
     * Generic names in label order (two or more for a combination drug).
     *
     * @return BelongsToMany<Generic, $this>
     */
    public function generics(): BelongsToMany
    {
        return $this->belongsToMany(Generic::class, 'product_generics')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    /** @return HasMany<ProductGeneric, $this> */
    public function productGenerics(): HasMany
    {
        return $this->hasMany(ProductGeneric::class);
    }

    /**
     * Product-level attribute values (e.g. Helmet Type = Full Face).
     *
     * @return HasMany<ProductAttributeValue, $this>
     */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    /**
     * Vehicles this part fits (spare parts only).
     *
     * @return BelongsToMany<VehicleModel, $this>
     */
    public function fitments(): BelongsToMany
    {
        return $this->belongsToMany(VehicleModel::class, 'product_fitments')
            ->withPivot(['year_from', 'year_to', 'notes'])
            ->withTimestamps();
    }

    /**
     * Search by name, part number, OEM number, SKU or barcode, and with
     * $drugNames (pharmacy shops) also by generic or brand name. Names match
     * anywhere ("amox" finds Amoxicillin, "clav" finds Co-amoxiclav).
     *
     * @param  Builder<Product>  $query
     */
    public function scopeSearch(Builder $query, string $term, bool $drugNames = false): void
    {
        $term = trim($term);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $q) use ($term, $like, $drugNames): void {
            $q->where('name', 'like', $like)
                ->orWhere('part_number', 'like', $like)
                ->orWhere('oem_numbers', 'like', $like)
                ->orWhereHas('variants', fn (Builder $v) => $v->where(
                    fn (Builder $w) => $w->where('sku', $term)->orWhere('barcode', $term)
                ));

            if ($drugNames) {
                // Matching generics come from the small generics table first;
                // products are then found through the product_generics index.
                $q->orWhereIn('products.id', ProductGeneric::query()
                    ->select('product_id')
                    ->whereIn('generic_id', Generic::query()->select('id')->where('name', 'like', $like)))
                    ->orWhereIn('products.id', DrugDetail::query()->select('product_id')->where('brand_name', 'like', $like));
            }
        });
    }

    /** Stock is held in batches with expiry dates (docs/PHARMACY.md §4.5). Services never track batches. */
    public function tracksBatches(): bool
    {
        return $this->track_stock && $this->track_batches;
    }

    /** A medicine: it has drug details (pharmacy shops only). Loads nothing when they are loaded. */
    public function isMedicine(): bool
    {
        return $this->relationLoaded('drugDetail') ? $this->drugDetail !== null : $this->drugDetail()->exists();
    }

    /**
     * Parts that fit a vehicle: a specific model, or any model of a make, and
     * optionally a year. A fitment with no years fits every year.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeFitsVehicle(Builder $query, ?int $vehicleModelId, ?int $year = null, ?int $makeId = null): void
    {
        if ($vehicleModelId === null && $makeId === null) {
            return;
        }

        $query->whereHas('fitments', function (Builder $q) use ($vehicleModelId, $year, $makeId): void {
            if ($vehicleModelId !== null) {
                $q->where('vehicle_models.id', $vehicleModelId);
            } else {
                $q->where('vehicle_models.vehicle_make_id', $makeId);
            }

            if ($year !== null) {
                $q->where(fn (Builder $y) => $y->whereNull('product_fitments.year_from')
                    ->orWhere('product_fitments.year_from', '<=', $year))
                    ->where(fn (Builder $y) => $y->whereNull('product_fitments.year_to')
                        ->orWhere('product_fitments.year_to', '>=', $year));
            }
        });
    }
}
