<?php

namespace Tests\Feature\Pos;

use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Enums\PaymentMethod;
use App\Enums\TaxCategory;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Services\CheckoutService;
use App\Services\Data\BatchInput;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\DrugData;
use App\Services\Data\PaymentInput;
use App\Services\ProductCatalogService;
use App\Services\ReceiptService;
use Illuminate\Support\Str;

/** Per-product tax category (docs/PHARMACY.md Q11): exempt lines carry no tax. */
class TaxCategoryTest extends PharmacyTestCase
{
    private ProductVariant $medicine; // exempt, 10.00 a tablet

    private ProductVariant $sundry;   // standard, 20.00

    protected function setUp(): void
    {
        parent::setUp();

        $catalog = app(ProductCatalogService::class);

        $this->medicine = $catalog->create(
            ['name' => 'Panadol 500 mg Tablets', 'unit' => 'Tablet'],
            [['price' => 1_000, 'opening_stock' => 100, 'opening_batch' => new BatchInput('B1', '12/2028')]],
            user: $this->owner,
            drug: new DrugData(['Paracetamol'], DosageForm::Tablet, DrugSchedule::Otc, 'Panadol', 500, 'mg'),
        )->variants()->firstOrFail();

        $this->sundry = $catalog->create(
            ['name' => 'Cotton wool', 'unit' => 'Pack'],
            [['price' => 2_000, 'opening_stock' => 100]],
            user: $this->owner,
        )->variants()->firstOrFail();
    }

    private function taxAt(float $rate, bool $inclusive = false): void
    {
        $this->shop->update(['tax_enabled' => $rate > 0, 'tax_rate' => $rate, 'prices_include_tax' => $inclusive]);
    }

    /** @return list<CartLine> */
    private function lines(): array
    {
        return [new CartLine($this->medicine->getKey(), 1), new CartLine($this->sundry->getKey(), 1)];
    }

    private function checkout(int $saleDiscount = 0, bool $offline = false, ?int $tillGrandTotal = null): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            // The clinic's cashiers may give no discount (pharmacy profile); the owner may.
            cashier: $saleDiscount > 0 ? $this->owner : $this->cashier,
            lines: $offline
                ? [new CartLine($this->medicine->getKey(), 1, tillUnitPrice: 1_000), new CartLine($this->sundry->getKey(), 1, tillUnitPrice: 2_000)]
                : $this->lines(),
            payments: [new PaymentInput(PaymentMethod::Cash, 10_000)],
            saleDiscount: $saleDiscount,
            uuid: $offline ? (string) Str::uuid() : null,
            isOfflineSync: $offline,
            soldAt: $offline ? now()->subMinute() : null,
            tillGrandTotal: $tillGrandTotal,
            tillTaxRate: $offline ? (float) $this->shop->tax_rate : null,
            tillPricesIncludeTax: $offline ? (bool) $this->shop->prices_include_tax : null,
        ));
    }

    /** @return array<string, array{tax: int, total: int, category: string|null}> sku => line */
    private function lineTaxes(Sale $sale): array
    {
        return $sale->items->mapWithKeys(fn ($item) => [$item->sku => [
            'tax' => $item->tax_amount,
            'total' => $item->line_total,
            'category' => $item->tax_category?->value,
        ]])->all();
    }

    public function test_tax_added_on_top_applies_to_the_standard_line_only(): void
    {
        $this->taxAt(10);

        $sale = $this->checkout();

        $this->assertSame([
            $this->medicine->sku => ['tax' => 0, 'total' => 1_000, 'category' => 'exempt'],
            $this->sundry->sku => ['tax' => 200, 'total' => 2_200, 'category' => 'standard'],
        ], $this->lineTaxes($sale));
        $this->assertSame(200, $sale->tax_total);
        $this->assertSame(3_200, $sale->grand_total);
    }

    public function test_tax_included_in_prices_is_split_out_of_the_standard_line_only(): void
    {
        $this->taxAt(18, inclusive: true);

        $sale = $this->checkout();

        // 2,000 incl. 18% => 2000 * 18 / 118 = 305.08
        $this->assertSame(305, $sale->tax_total);
        $this->assertSame(3_000, $sale->grand_total);
        $this->assertSame(0, $sale->items->firstWhere('sku', $this->medicine->sku)?->tax_amount);
    }

    public function test_a_sale_discount_is_spread_over_both_lines_and_only_the_standard_share_is_taxed(): void
    {
        $this->taxAt(10);

        // 300 off 3,000: 100 off the medicine, 200 off the cotton wool, then 10% of 1,800.
        $sale = $this->checkout(saleDiscount: 300);

        $this->assertSame([
            $this->medicine->sku => ['tax' => 0, 'total' => 900, 'category' => 'exempt'],
            $this->sundry->sku => ['tax' => 180, 'total' => 1_980, 'category' => 'standard'],
        ], $this->lineTaxes($sale));
    }

    public function test_the_quote_matches_checkout_and_names_each_lines_category(): void
    {
        $this->taxAt(10);

        $quote = app(CheckoutService::class)->quote($this->lines(), 0, $this->cashier);

        $this->assertSame(200, $quote['tax_total']);
        $this->assertSame(3_200, $quote['grand_total']);
        $this->assertSame(['exempt', 'standard'], array_column($quote['lines'], 'tax_category'));
    }

    public function test_an_offline_sale_priced_the_same_way_by_the_till_replays_without_review(): void
    {
        $this->taxAt(10);

        // pricing.ts: 1,000 exempt + 2,000 + 10% = 3,200, the same as the server.
        $sale = $this->checkout(offline: true, tillGrandTotal: 3_200);

        $this->assertSame(200, $sale->tax_total);
        $this->assertSame(3_200, $sale->grand_total);
        $this->assertFalse($sale->needs_review, json_encode($sale->review_reasons) ?: '');
    }

    public function test_an_offline_till_that_taxed_an_exempt_line_is_flagged_for_review(): void
    {
        $this->taxAt(10);

        // An out-of-date till that still taxed the medicine: 3,300.
        $sale = $this->checkout(offline: true, tillGrandTotal: 3_300);

        $this->assertSame(3_200, $sale->grand_total);
        $this->assertTrue($sale->needs_review);
        $this->assertContains('total', array_column($sale->review_reasons ?? [], 'type'));
    }

    public function test_a_shop_without_tax_charges_none_on_either_category(): void
    {
        $this->taxAt(0);

        $sale = $this->checkout();

        $this->assertSame(0, $sale->tax_total);
        $this->assertSame(3_000, $sale->grand_total);
        $this->assertSame(['exempt', 'standard'], $sale->items->map(fn ($item) => $item->tax_category?->value)->all());
    }

    public function test_the_receipt_marks_exempt_lines(): void
    {
        $this->taxAt(10);

        $receipt = app(ReceiptService::class)->sale($this->checkout());

        $this->assertSame([true, false], array_column($receipt['items'], 'taxExempt'));
    }

    public function test_changing_a_products_category_changes_future_sales_only(): void
    {
        $this->taxAt(10);
        $before = $this->checkout();

        $this->medicine->product->update(['tax_category' => TaxCategory::Standard]);
        $after = $this->checkout();

        $this->assertSame(200, $before->fresh()?->tax_total);
        $this->assertSame('exempt', $before->items->firstWhere('sku', $this->medicine->sku)?->fresh()?->tax_category?->value);
        $this->assertSame(300, $after->tax_total);
    }
}
