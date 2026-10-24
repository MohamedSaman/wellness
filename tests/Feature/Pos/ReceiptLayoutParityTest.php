<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Services\CheckoutService;
use App\Services\Receipts\EscPos;
use App\Services\Receipts\ReceiptLayout;
use App\Services\ReceiptService;
use App\Services\ReturnService;
use Illuminate\Support\Facades\Process;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * The till lays out the receipts of sales it makes offline with its own
 * copy of the layout (resources/js/lib/receipt/layout.ts and escpos.ts), a
 * mirror of ReceiptLayout::sale() and EscPos::encode() (docs/PHARMACY.md §9,
 * branch 9). This runs the till's code under node (type stripping, node 22.6
 * or later) on the same bills and expects the same lines and bytes.
 * Skipped when node is not installed.
 */
class ReceiptLayoutParityTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    public function test_the_tills_layout_prints_exactly_what_the_server_prints(): void
    {
        $this->requireNode();
        $this->stockTheClinic();
        $this->receive($this->panadol, 'PN2611', '03/2027', 120);
        $receipts = app(ReceiptService::class);

        $gen = $this->billAtGeneral();
        $opd = $this->opdBill();
        $box = $gen->items->firstWhere('sku', $this->panadol->sku);
        app(ReturnService::class)->process($gen, [$box->getKey() => 10], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner, inBaseUnits: true);
        $voided = $this->billAtGeneral();
        app(CheckoutService::class)->void($voided, $this->owner, 'Rang up twice');

        $sales = [
            'gen' => $receipts->sale($this->billAtGeneral()),
            'opd' => $receipts->sale($opd),
            'opd totals' => [...$receipts->sale($opd), 'opdReceiptMedicines' => 'totals'],
            'returned, reprinted' => $receipts->sale($gen->fresh() ?? $gen, $receipts->recordReprint($gen, $this->owner, null)),
            'voided' => $receipts->sale($voided->fresh() ?? $voided),
            'taxed, discounted, two locations, odd text' => $this->oddSale(),
        ];

        $shops = [];
        foreach ([80, 58] as $paperWidth) {
            foreach ([[true, false, true], [true, true, false], [false, true, true], [false, false, false]] as [$batch, $expiry, $prescriber]) {
                $shop = $receipts->shop();
                $shop['receipt'] = ['paperWidth' => $paperWidth, 'showBatch' => $batch, 'showExpiry' => $expiry, 'showPrescriber' => $prescriber];
                $shops[] = $shop;
            }
        }
        $shops[] = [...$receipts->shop(), 'name' => 'Doctor’s Clinic & Pharmacy Ltd. of Kandy Central', 'header' => "Reg. no. PH 1234\n\nSuperlongunbrokenwordthatcannotfitonalineatall", 'footer' => null, 'taxNumber' => 'VAT-99', 'decimals' => 0, 'currency' => 'JPY'];
        $shops[] = [...$receipts->shop(), 'receipt' => null, 'phone' => null, 'address' => null];

        $cases = [];
        foreach ($sales as $name => $sale) {
            foreach ($shops as $i => $shop) {
                $cases["{$name} / shop {$i}"] = ['shop' => $shop, 'sale' => $sale];
            }
        }

        $till = $this->runTill(array_values($cases));

        foreach (array_keys($cases) as $n => $name) {
            $server = (new ReceiptLayout($cases[$name]['shop']))->sale($cases[$name]['sale']);

            $this->assertSame(array_column($server, 'text'), array_column($till[$n]['lines'], 'text'), "Lines differ: {$name}");
            $this->assertSame($server, $till[$n]['lines'], "Styles differ: {$name}");
            $this->assertSame(base64_encode(EscPos::encode($server)), $till[$n]['escpos'], "ESC/POS differs: {$name}");
        }
    }

    /** @return array<string, mixed> */
    private function oddSale(): array
    {
        $sale = app(ReceiptService::class)->sale($this->billAtGeneral());

        return [
            ...$sale,
            'taxRate' => 8.5,
            'pricesIncludeTax' => false,
            'discountTotal' => 1_250,
            'taxTotal' => 9_000,
            'refundedTotal' => 500,
            'items' => [
                [...$sale['items'][0], 'location' => 'General Pharmacy', 'discount' => 1_250, 'taxExempt' => false,
                    'description' => 'Café crème “extra” — Paracetamol/Caffeine Film-coated Tablets (Box of 30)', 'packName' => null],
                [...$sale['items'][1], 'location' => 'Doctor’s Medicines', 'returnedQuantity' => '2', 'returnedBaseQuantity' => '2',
                    'batches' => [['batchNo' => null, 'expiry' => null, 'quantity' => '3'], ...$sale['items'][1]['batches']]],
                [...$sale['items'][1], 'location' => null, 'unitPrice' => null, 'lineTotal' => null, 'unitName' => null, 'strength' => '  ', 'returnedQuantity' => '0.5', 'returnedBaseQuantity' => '15', 'baseUnitName' => 'Capsule'],
            ],
            'payments' => [...$sale['payments'], ['method' => 'Cash', 'amount' => 123_456_789, 'reference' => null]],
        ];
    }

    private function requireNode(): void
    {
        $version = Process::run(['node', '--version']);

        if (! $version->successful() || ! preg_match('/^v(\d+)\.(\d+)/', trim($version->output()), $m) || [(int) $m[1], (int) $m[2]] < [22, 6]) {
            $this->markTestSkipped('Needs node 22.6 or later (type stripping) to run the till\'s layout.');
        }
    }

    /**
     * @param  list<array{shop: array<string, mixed>, sale: array<string, mixed>}>  $cases
     * @return list<array{lines: list<array{text: string, bold: bool, tall: bool}>, escpos: string}>
     */
    private function runTill(array $cases): array
    {
        $result = Process::path(base_path())
            ->input(json_encode(['cases' => $cases], JSON_THROW_ON_ERROR))
            ->run(['node', '--experimental-strip-types', '--no-warnings', 'tests/js/render-receipt.mjs']);

        $this->assertTrue($result->successful(), $result->errorOutput());

        return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
    }
}
