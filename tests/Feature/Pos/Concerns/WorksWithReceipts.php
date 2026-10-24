<?php

namespace Tests\Feature\Pos\Concerns;

use App\Enums\BillType;
use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Enums\PaymentMethod;
use App\Enums\StorageCondition;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\DrugData;
use App\Services\Data\PaymentInput;
use App\Services\ProductCatalogService;
use App\Services\ReceiptService;

/**
 * Bills to print (docs/PHARMACY.md §9, branch 9), on the demo clinic with the
 * clock fixed (BatchTestCase + WorksWithCheckout):
 *
 * - billAtGeneral(): T1-000001 at General Pharmacy: a box of 30 Panadol
 *   500 mg, 15 Amoxil 250 mg capsules from two batches on a prescription,
 *   paid by bank transfer;
 * - opdBill(): T2-000001 at the OPD counter: the consultation, a bottle of
 *   syrup and 10 capsules for a Medicines amount of LKR 850.
 */
trait WorksWithReceipts
{
    protected ProductVariant $panadol;

    protected ProductVariant $amoxil;

    /** A medicine with a strength, a base unit and packs, batch-tracked, no stock yet. */
    protected function drug(
        string $name,
        float $strength,
        string $strengthUnit,
        string $baseUnit = 'Tablet',
        array $packs = [['name' => 'Box of 30', 'factor' => 30, 'price' => 54_000]],
        int $price = 2_000,
        DrugSchedule $schedule = DrugSchedule::Otc,
        ?StorageCondition $storage = null,
        DosageForm $form = DosageForm::Tablet,
    ): ProductVariant {
        return app(ProductCatalogService::class)->create(
            ['name' => $name, 'unit' => $baseUnit],
            [['price' => $price, 'cost' => (int) ($price / 2), 'packs' => $packs]],
            user: $this->owner,
            drug: new DrugData(['Generic of '.$name], $form, $schedule, strengthValue: $strength, strengthUnit: $strengthUnit, storageCondition: $storage),
        )->variants()->firstOrFail();
    }

    protected function stockTheClinic(): void
    {
        $this->panadol = $this->drug('Panadol Tablets', 500, 'mg');
        $this->amoxil = $this->drug('Amoxil Capsules', 250, 'mg', 'Capsule', [], 3_500, DrugSchedule::PrescriptionOnly, form: DosageForm::Capsule);

        $this->receive($this->panadol, 'PN2611', '03/2027', 60);
        $this->receive($this->amoxil, 'AX0921', '11/2026', 5);
        $this->receive($this->amoxil, 'AX1102', '02/2027', 50);
        $this->receive($this->amoxil, 'AX1102', '02/2027', 50, $this->doctors);
    }

    protected function billAtGeneral(): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->genTill(),
            cashier: $this->cashier,
            lines: [
                new CartLine($this->panadol->getKey(), 1, unitId: $this->unitNamed($this->panadol, 'Box of 30')),
                new CartLine($this->amoxil->getKey(), 15, prescriber: 'Dr. S. Perera', prescriptionRef: 'RX-1042'),
            ],
            payments: [new PaymentInput(PaymentMethod::BankTransfer, 106_500, 'BOC 448812')],
        ));
    }

    protected function opdBill(): Sale
    {
        $consultation = $this->consultation();
        $syrup = $this->syrup('Paracetamol Syrup', 500, 25_000, 100);
        $this->receive($syrup, 'PS0426', '06/2027', 500, $this->doctors);

        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->docTill(),
            cashier: $this->cashierAt($this->doctors),
            lines: [
                new CartLine($consultation->getKey(), 1),
                new CartLine($syrup->getKey(), 1, unitId: $this->unitNamed($syrup, 'Bottle'), prescriber: 'Dr. S. Perera'),
                new CartLine($this->amoxil->getKey(), 10, prescriber: 'Dr. S. Perera'),
            ],
            payments: [new PaymentInput(PaymentMethod::Cash, 300_000)],
            billType: BillType::Opd,
            opdMedicinesAmount: 85_000,
        ));
    }

    protected function unitNamed(ProductVariant $variant, string $name): int
    {
        return (int) $variant->units()->where('name', $name)->value('id');
    }

    /**
     * A document as the printer prints it: the lines, one per row, at 80 or 58 mm.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function printed(string $document, array $payload, int $paperWidth = 80): string
    {
        $receipts = app(ReceiptService::class);
        $shop = $receipts->shop();
        $shop['receipt']['paperWidth'] = $paperWidth;

        return implode("\n", array_column($receipts->thermal($document, $payload, $shop)['lines'], 'text'));
    }
}
