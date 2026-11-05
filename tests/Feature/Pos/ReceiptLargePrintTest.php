<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Services\CheckoutService;
use App\Services\Receipts\EscPos;
use App\Services\Receipts\ReceiptLayout;
use App\Services\ReceiptService;
use App\Services\ReturnService;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * A pharmacy's receipts in the browser: 36 characters across 80 mm paper, so
 * the text is 12.6 px and still inside the 72 mm the thermal head reaches
 * (48 columns of 12 px measure 91.5 mm, and Chrome would scale the page down).
 * The amount the document is about is double height. ESC/POS keeps the
 * printer's own 48 columns. 58 mm paper keeps its 32.
 */
class ReceiptLargePrintTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockTheClinic();
    }

    /** @return array<string, array{'sale'|'refund'|'void', array<string, mixed>}> */
    private function documents(): array
    {
        $receipts = app(ReceiptService::class);
        $refunded = $this->billAtGeneral();
        $box = $refunded->items->firstWhere('sku', $this->panadol->sku);
        $return = app(ReturnService::class)->process($refunded, [$box->getKey() => 10], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner, inBaseUnits: true);
        $voided = $this->billAtGeneral();
        app(CheckoutService::class)->void($voided, $this->owner, 'Rang up twice');

        return [
            'bill' => ['sale', $receipts->sale($this->billAtGeneral())],
            'opd bill' => ['sale', $receipts->sale($this->opdBill())],
            'reprint' => ['sale', $receipts->sale($refunded, $receipts->recordReprint($refunded, $this->owner, null))],
            'refund slip' => ['refund', $receipts->refund($return)],
            'void slip' => ['void', $receipts->voidSlip($voided->fresh() ?? $voided)],
        ];
    }

    public function test_every_document_fits_36_columns_and_its_grand_total_is_double_height(): void
    {
        foreach ($this->documents() as $name => [$document, $payload]) {
            $thermal = app(ReceiptService::class)->thermal($document, $payload);

            $this->assertSame(36, $thermal['columns'], $name);
            $this->assertTrue($thermal['largePrint'], $name);

            foreach ($thermal['lines'] as $line) {
                $this->assertLessThanOrEqual(36, strlen($line['text']), "{$name}: [{$line['text']}]");
            }

            $total = array_values(array_filter($thermal['lines'], fn (array $line): bool => (bool) preg_match('/^(TOTAL|REFUND TOTAL|TO GIVE BACK) /', $line['text'])));
            $this->assertNotEmpty($total, $name);
            $this->assertTrue($total[0]['bold'] && $total[0]['tall'], $name);
        }
    }

    public function test_esc_pos_keeps_the_printers_48_columns_and_never_stretches_the_total(): void
    {
        foreach ($this->documents() as $name => [$document, $payload]) {
            $receipts = app(ReceiptService::class);
            $printer = ReceiptLayout::forPrinter($receipts->shop());
            $expected = $receipts->thermal($document, $payload, $printer);

            $this->assertSame(48, $expected['columns'], $name);
            $this->assertFalse($expected['largePrint'], $name);
            $this->assertSame(base64_encode(EscPos::encode($expected['lines'])), $receipts->thermal($document, $payload)['escpos'], $name);
            $this->assertSame([], array_filter($expected['lines'], fn (array $line): bool => $line['tall'] && str_starts_with($line['text'], 'TOTAL')), $name);
        }
    }

    public function test_58_mm_paper_keeps_its_32_columns(): void
    {
        $this->setShopSetting('receipt_paper_width', 58);
        $receipts = app(ReceiptService::class);

        $this->assertSame(32, $receipts->thermal('sale', $receipts->sale($this->billAtGeneral()))['columns']);
    }
}
