<?php

namespace App\Filament\Support;

use App\Models\Shop;

/**
 * Made-up bills for the live preview on Receipt settings, in the shape of
 * ReceiptService's payloads: a General Pharmacy bill (a box, loose tablets
 * from two batches, a prescription line, a bank transfer), an OPD bill
 * (consultation and one Medicines amount) and a refund slip. Nothing here
 * is stored.
 */
final class ReceiptPreviewSamples
{
    /**
     * @return array{gen: array<string, mixed>, opd: array<string, mixed>, refund: array<string, mixed>}
     */
    public static function for(Shop $shop, string $opdMedicines): array
    {
        $till = ['code' => 'T1', 'name' => 'Front counter', 'location' => $shop->defaultLocation()->name];
        $base = [
            'status' => 'completed',
            'billType' => 'standard',
            'opdReceiptMedicines' => $opdMedicines,
            'medicinesTotal' => null,
            'soldAt' => now()->timezone($shop->timezone)->format('Y-m-d H:i'),
            'cashier' => 'Cashier',
            'till' => $till,
            'customerName' => null,
            'customerPhone' => null,
            'discountTotal' => 0,
            'taxTotal' => 0,
            'taxRate' => $shop->tax_enabled ? (float) $shop->tax_rate : 0.0,
            'pricesIncludeTax' => (bool) $shop->prices_include_tax,
            'refundedTotal' => 0,
            'reprint' => null,
        ];
        $panadol = [
            'description' => 'Panadol Tablets (Box of 30)', 'sku' => 'PAN-500', 'strength' => '500 mg', 'unitName' => 'Box of 30', 'baseUnitName' => 'Tablet',
            'quantity' => '1', 'returnedQuantity' => null, 'unitPrice' => 54_000, 'discount' => 0, 'lineTotal' => 54_000, 'taxExempt' => true, 'isOpdMedicine' => false,
            'prescriber' => null, 'prescriptionRef' => null, 'batches' => [['batchNo' => 'PN2611', 'expiry' => '03/2027', 'quantity' => '30']],
        ];
        $amoxil = [
            'description' => 'Amoxil Capsules', 'sku' => 'AMX-250', 'strength' => '250 mg', 'unitName' => 'Capsule', 'baseUnitName' => 'Capsule',
            'quantity' => '15', 'returnedQuantity' => null, 'unitPrice' => 3_500, 'discount' => 0, 'lineTotal' => 52_500, 'taxExempt' => true, 'isOpdMedicine' => false,
            'prescriber' => 'Dr. S. Perera', 'prescriptionRef' => 'RX-1042',
            'batches' => [['batchNo' => 'AX0921', 'expiry' => '11/2026', 'quantity' => '5'], ['batchNo' => 'AX1102', 'expiry' => '02/2027', 'quantity' => '10']],
        ];
        $syrup = [
            'description' => 'Paracetamol Syrup (Bottle)', 'sku' => 'PCM-SYR', 'strength' => '120 mg/5 ml', 'unitName' => 'Bottle', 'baseUnitName' => 'ml',
            'quantity' => '1', 'returnedQuantity' => null, 'unitPrice' => null, 'discount' => null, 'lineTotal' => null, 'taxExempt' => true, 'isOpdMedicine' => true,
            'prescriber' => 'Dr. S. Perera', 'prescriptionRef' => null, 'batches' => [['batchNo' => 'PS0426', 'expiry' => '06/2027', 'quantity' => '100']],
        ];

        $gen = [
            ...$base,
            'invoiceNo' => 'T1-000123',
            'subtotal' => 106_500,
            'grandTotal' => 106_500,
            'paidTotal' => 106_500,
            'changeDue' => 0,
            'items' => [$panadol, $amoxil],
            'payments' => [['method' => 'Bank Transfer', 'amount' => 106_500, 'reference' => 'BOC 448812']],
        ];

        $opd = [
            ...$base,
            'invoiceNo' => 'T2-000045',
            'billType' => 'opd',
            'medicinesTotal' => 85_000,
            'till' => ['code' => 'T2', 'name' => 'OPD counter', 'location' => $shop->locations()->where('is_default', false)->value('name') ?? $till['location']],
            'subtotal' => 235_000,
            'grandTotal' => 235_000,
            'paidTotal' => 300_000,
            'changeDue' => 65_000,
            'items' => [
                ['description' => 'Consultation', 'sku' => 'CONSULT', 'strength' => null, 'unitName' => 'Visit', 'baseUnitName' => 'Visit', 'quantity' => '1', 'returnedQuantity' => null,
                    'unitPrice' => 150_000, 'discount' => 0, 'lineTotal' => 150_000, 'taxExempt' => true, 'isOpdMedicine' => false, 'prescriber' => null, 'prescriptionRef' => null],
                $syrup,
                [...$amoxil, 'description' => 'Amoxil Capsules', 'quantity' => '10', 'unitPrice' => null, 'discount' => null, 'lineTotal' => null, 'isOpdMedicine' => true,
                    'batches' => [['batchNo' => 'AX1102', 'expiry' => '02/2027', 'quantity' => '10']]],
            ],
            'payments' => [['method' => 'Cash', 'amount' => 300_000, 'reference' => null]],
        ];

        $refund = [
            'returnNo' => 'T1-000123-R1',
            'returnedAt' => $base['soldAt'],
            'user' => 'Owner',
            'reason' => 'Changed mind',
            'note' => null,
            'refundTotal' => 54_000,
            'method' => 'Cash',
            'reference' => null,
            'medicinesRefund' => null,
            'sale' => [...$gen, 'items' => []],
            'items' => [[...$panadol, 'refund' => 54_000, 'restocked' => true, 'packName' => 'Box of 30']],
            'reprint' => null,
        ];

        return ['gen' => $gen, 'opd' => $opd, 'refund' => $refund];
    }
}
