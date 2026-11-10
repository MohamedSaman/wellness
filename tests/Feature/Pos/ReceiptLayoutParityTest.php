<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Enums\StockMovementType;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\Receipts\EscPos;
use App\Services\Receipts\ReceiptLayout;
use App\Services\ReceiptService;
use App\Services\ReturnService;
use App\Services\StockService;
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

        // Branch 14: prices typed at the till, one up and one down. List prices nothing else costs.
        $dressing = $this->openPriceItem('Wound dressing', 51_234, max: 150_000, min: 20_000, tracksStock: true);
        app(StockService::class)->record($dressing, $this->general, StockMovementType::StockIn, 10, $this->owner, unitCost: 10_000);
        $cbs = $this->openPriceItem('CBS', 102_345, max: 300_000, ask: true);
        $typed = app(CheckoutService::class)->checkout(new CheckoutData($this->till, $this->cashier, [
            new CartLine($dressing->getKey(), 2, unitPriceOverride: 120_000),
            new CartLine($cbs->getKey(), 1, unitPriceOverride: 80_000),
        ], [new PaymentInput(PaymentMethod::Cash, 1_000_000)]));

        $sales = [
            'gen' => $receipts->sale($this->billAtGeneral()),
            'typed prices' => $receipts->sale($typed),
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
        foreach ([80, 58] as $paperWidth) {
            $shops[] = [...$receipts->shop(), 'receipt' => ['paperWidth' => $paperWidth, 'showBatch' => true, 'showExpiry' => true, 'showPrescriber' => true, 'largePrint' => true]];
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
            $printer = (new ReceiptLayout(ReceiptLayout::forPrinter($cases[$name]['shop'])))->sale($cases[$name]['sale']);
            $this->assertSame(base64_encode(EscPos::encode($printer)), $till[$n]['escpos'], "ESC/POS differs: {$name}");
        }

        // A typed price prints as charged, never the list price it replaced.
        $text = implode("\n", array_column((new ReceiptLayout($receipts->shop()))->sale($sales['typed prices']), 'text'));
        $this->assertStringContainsString('2 pcs x 1,200.00', $text);
        $this->assertStringContainsString('1 pcs x 800.00', $text);
        $this->assertStringNotContainsString('512.34', $text);
        $this->assertStringNotContainsString('1,023.45', $text);
    }

    /**
     * The patient's row (branch 13) under every combination of the two
     * settings, on both papers and in large print: the same lines and bytes.
     */
    public function test_the_tills_layout_prints_the_patient_exactly_as_the_server(): void
    {
        $this->requireNode();
        $this->stockTheClinic();
        $receipts = app(ReceiptService::class);
        $sale = $receipts->sale($this->billAtGeneral());

        $patients = [
            'name and age' => ['name' => 'Nimal Perera', 'age' => '34 y'],
            'baby' => ['name' => 'Baby Silva', 'age' => '8 m'],
            'name only' => ['name' => 'Kamala Fernando', 'age' => null],
            'age only' => ['name' => null, 'age' => '71 y'],
            'long, odd text' => ['name' => 'Wijesinghe Mudiyanselage Ralahamillage Nimalasiri Bandara Gunawardena', 'age' => '102 y'],
            'accents' => ['name' => '  Zoë O’Brien–Perera ', 'age' => '23 m'],
            'none' => null,
        ];

        $cases = [];
        foreach ($patients as $name => $patient) {
            foreach ([[80, false], [58, false], [80, true]] as [$paperWidth, $large]) {
                foreach ([[true, true], [true, false], [false, true], [false, false]] as [$showName, $showAge]) {
                    $options = ['paperWidth' => $paperWidth, 'showBatch' => true, 'showExpiry' => false, 'showPrescriber' => true, 'showPatientName' => $showName, 'showPatientAge' => $showAge];
                    $cases["{$name} / {$paperWidth}".($large ? ' large' : '')." / name {$showName} age {$showAge}"] = [
                        'shop' => [...$receipts->shop(), 'receipt' => $large ? [...$options, 'largePrint' => true] : $options],
                        'sale' => [...$sale, 'patient' => $patient],
                    ];
                }
            }
        }

        $till = $this->runTill(array_values($cases));

        foreach (array_keys($cases) as $n => $name) {
            $server = (new ReceiptLayout($cases[$name]['shop']))->sale($cases[$name]['sale']);

            $this->assertSame($server, $till[$n]['lines'], "Lines differ: {$name}");
            $printer = (new ReceiptLayout(ReceiptLayout::forPrinter($cases[$name]['shop'])))->sale($cases[$name]['sale']);
            $this->assertSame(base64_encode(EscPos::encode($printer)), $till[$n]['escpos'], "ESC/POS differs: {$name}");
        }

        // What each combination prints, on the server.
        $text = fn (array $options, ?array $patient): string => implode("\n", array_column((new ReceiptLayout([...$receipts->shop(), 'receipt' => ['paperWidth' => 80, 'showBatch' => true, 'showExpiry' => true, 'showPrescriber' => false, ...$options]]))->sale([...$sale, 'patient' => $patient]), 'text'));
        $nimal = ['name' => 'Nimal Perera', 'age' => '34 y'];
        $this->assertMatchesRegularExpression('/^Patient +Nimal Perera \(34 y\)$/m', $text(['showPatientName' => true, 'showPatientAge' => true], $nimal));
        $this->assertMatchesRegularExpression('/^Patient +Nimal Perera$/m', $text(['showPatientName' => true], $nimal));
        $this->assertMatchesRegularExpression('/^Age +34 y$/m', $text(['showPatientAge' => true], $nimal));
        $this->assertStringNotContainsString('Nimal', $text([], $nimal));
        $this->assertStringNotContainsString('34 y', $text([], $nimal));
        $this->assertStringNotContainsString('Patient', $text(['showPatientName' => true, 'showPatientAge' => true], null));
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
