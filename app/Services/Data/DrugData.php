<?php

namespace App\Services\Data;

use App\Enums\DosageForm;
use App\Enums\DrugRoute;
use App\Enums\DrugSchedule;
use App\Enums\StorageCondition;

/**
 * A medicine's details as entered (docs/PHARMACY.md §4.1). The manufacturer
 * is the product's brand_id and the tax category a product column, so
 * neither is here.
 */
final readonly class DrugData
{
    /**
     * @param  list<string>  $genericNames  in label order; two or more for a combination drug
     */
    public function __construct(
        public array $genericNames,
        public DosageForm $dosageForm,
        public DrugSchedule $schedule,
        public ?string $brandName = null,
        public ?float $strengthValue = null, // 500 (mg), 5 (mg/5 ml)
        public ?string $strengthUnit = null,
        public ?string $storageNote = null,
        public ?StorageCondition $storageCondition = null,
        public ?string $registrationNo = null, // NMRA
        public ?DrugRoute $route = null,
        // Stop selling this many days before expiry; null = the shop's setting (min_days_to_expiry_for_sale).
        public ?int $minDaysToExpiry = null,
    ) {}
}
