<?php

namespace Tests\Feature\Pos\Mysql;

use App\Enums\BillType;
use App\Enums\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * An OPD bill on MySQL (docs/PHARMACY.md §9, branch 8): the consultation,
 * two syrups in ml and tablets for one Medicines amount. The unpriced line
 * weighs by its DECIMAL(20,4) cost, the allocation adds up exactly (and the
 * quote shows only the bill's Medicines figures), and FEFO takes the
 * batches at Doctor's Medicines.
 */
#[Group('mysql')]
class OpdCheckoutMysqlTest extends MysqlTestCase
{
    use WorksWithCheckout;

    public function test_an_opd_bill_with_syrups_in_ml_and_tablets_on_mysql(): void
    {
        $doc = $this->docTill();
        $doctor = $this->cashierAt($this->doctors);
        $consult = $this->consultation(150_000);
        $paracetamol = $this->syrup();
        $cough = $this->syrup('Cough Syrup', 700, 30_000, 100);
        $tabs = $this->medicine();

        // An unpriced tablet, weighed by its cost per base unit (DECIMAL(20,4)).
        $amox = $this->medicine('Amoxicillin 250 mg Capsules', []);
        $amox->units()->where('is_base', true)->firstOrFail()->prices()->delete();

        $this->receive($paracetamol, 'P1', '03/2027', 120, $this->doctors, '300.5000');
        $this->receive($cough, 'C1', '12/2026', 50, $this->doctors, '412.3333');
        $this->receive($cough, 'C2', '06/2027', 100, $this->doctors, '412.3333');
        $this->receive($tabs, 'SOON', '11/2026', 4, $this->doctors);
        $this->receive($tabs, 'LATER', '03/2027', 50, $this->doctors);
        $this->receive($amox, 'A1', '03/2027', 30, $this->doctors, '1234.5678');

        $lines = [
            new CartLine($consult->getKey(), 1, unitPriceOverride: 120_000),
            new CartLine($paracetamol->getKey(), 15),   // list 7,500
            new CartLine($cough->getKey(), 60),         // list 42,000, from C1 then C2
            new CartLine($tabs->getKey(), 6),           // list 12,000, from SOON then LATER
            new CartLine($amox->getKey(), 3),           // cost 1,235 x 3 = 3,705
        ];

        foreach ([0, 1, 7, 65_204, 100_001, 999_999] as $amount) {
            $quote = app(CheckoutService::class)->quote($lines, 0, $doctor, $doc, BillType::Opd, $amount);
            $this->assertSame($amount, $quote['medicines_total'], "Amount {$amount}");
            $this->assertSame(120_000 + $amount, $quote['grand_total'], "Amount {$amount}");
            // The shares stay on the server.
            $this->assertSame([120_000, null, null, null, null], array_column($quote['lines'], 'line_total'), "Amount {$amount}");
        }

        $sale = app(CheckoutService::class)->checkout(new CheckoutData(
            device: $doc,
            cashier: $doctor,
            lines: $lines,
            payments: [new PaymentInput(PaymentMethod::BankTransfer, 220_001, 'TRF 1')],
            uuid: (string) Str::uuid(),
            billType: BillType::Opd,
            opdMedicinesAmount: 100_001,
        ));

        $sale = Sale::query()->with('items.batches')->findOrFail($sale->getKey());
        $this->assertSame(BillType::Opd, $sale->bill_type);
        $this->assertSame(220_001, $sale->grand_total);
        $this->assertSame(100_001, $sale->opd_medicines_amount);
        $this->assertSame(100_001, $sale->items->where('is_opd_medicine', true)->sum('line_total'));
        $this->assertSame(120_000, $sale->items[0]->unit_price);
        $this->assertSame(150_000, $sale->items[0]->list_price);
        $this->assertNull($sale->items[4]->list_price);
        $this->assertSame([301, 412, 1_000, 1_235], $sale->items->slice(1)->map(fn (SaleItem $item): int => $item->unit_cost)->values()->all());

        // Weights 7,500 : 42,000 : 12,000 : 3,705 of 100,001 (the odd units to the largest).
        $this->assertSame([11_502, 64_414, 18_403, 5_682], $sale->items->slice(1)->pluck('line_total')->values()->all());

        $this->assertSame(['C1' => 50.0, 'C2' => 10.0], $sale->items[2]->batches->mapWithKeys(fn ($piece): array => [$piece->batch_no => (float) $piece->quantity])->all());
        $this->assertSame(['SOON' => 4.0, 'LATER' => 2.0], $sale->items[3]->batches->mapWithKeys(fn ($piece): array => [$piece->batch_no => (float) $piece->quantity])->all());
        $this->assertSame(['P1' => 105.0], $this->batchQuantities($paracetamol, $this->doctors));
        $this->assertSame(['C2' => 90.0], $this->batchQuantities($cough, $this->doctors));
        $this->assertLedgerMatches();
    }
}
