<?php

namespace Tests\Feature\Pos;

use App\Exceptions\PosException;
use App\Models\ProductVariant;
use App\Models\VariantUnit;
use App\Services\BarcodeService;
use App\Services\Data\LabelRequest;
use App\Services\ProductCatalogService;
use App\Support\Barcode;
use Tests\Feature\Pos\Concerns\WorksWithLabels;

/**
 * In-store barcodes (docs/PHARMACY.md §9, branch 7b): EAN-13 with the GS1
 * check digit, 200 + the item's id or 201 + the pack's id, unique across
 * items and packs, given only to what has no barcode and never replacing a
 * manufacturer's.
 */
class LabelBarcodesTest extends BatchTestCase
{
    use WorksWithLabels;

    private function barcodes(): BarcodeService
    {
        return app(BarcodeService::class);
    }

    public function test_check_digits_follow_gs1_for_ean13_ean8_and_upc_a(): void
    {
        $this->assertTrue(Barcode::isValidEan13('4006381333931'));
        $this->assertFalse(Barcode::isValidEan13('4006381333932'));
        $this->assertTrue(Barcode::isValidEan13('9780306406157'));
        $this->assertTrue(Barcode::isValidEan8('96385074'));
        $this->assertFalse(Barcode::isValidEan8('96385075'));
        $this->assertTrue(Barcode::isValidUpcA('036000291452'));
        $this->assertSame(7, Barcode::ean13CheckDigit('978030640615'));

        $this->assertSame('2000000000015', Barcode::inStoreEan13(1));
        $this->assertSame('2010000012345', Barcode::inStoreEan13(1234, Barcode::PACK_PREFIX));
    }

    public function test_the_label_symbology_follows_the_code(): void
    {
        $this->assertSame(Barcode::EAN13, Barcode::symbology('4006381333931'));
        $this->assertSame(Barcode::UPCA, Barcode::symbology('036000291452'));
        $this->assertSame(Barcode::EAN8, Barcode::symbology('96385074'));
        // A wrong check digit is not an EAN: it prints as Code 128, exactly as typed.
        $this->assertSame(Barcode::CODE128, Barcode::symbology('4006381333932'));
        $this->assertSame(Barcode::CODE128, Barcode::symbology('BOX-CET-10'));
        $this->assertNull(Barcode::symbology("tab\there"));

        $this->assertSame('2 000000 000015', Barcode::humanReadable('2000000000015'));
    }

    public function test_in_store_codes_are_the_2xx_range_never_a_manufacturers(): void
    {
        $this->assertTrue(Barcode::isInStore('2000000000015'));
        $this->assertTrue(Barcode::isInStore('2990000000019'));
        $this->assertFalse(Barcode::isInStore('4790000000013'), 'Sri Lankan manufacturer prefix');
        $this->assertFalse(Barcode::isInStore('2000000000016'), 'Wrong check digit');
        $this->assertFalse(Barcode::isInStore(null));
    }

    public function test_an_item_without_a_barcode_gets_200_and_its_id(): void
    {
        $panadol = $this->medicine();

        $this->assertSame(Barcode::inStoreEan13($panadol->getKey(), 200), $panadol->barcode);
        $this->assertTrue(Barcode::isValidEan13((string) $panadol->barcode));
        $this->assertNull(VariantUnit::query()->where('product_variant_id', $panadol->getKey())->where('is_base', false)->value('barcode'), 'Packs get theirs when labels print');
    }

    public function test_a_pack_gets_201_and_its_id_when_its_labels_print_and_keeps_it(): void
    {
        $this->enableLabels();
        $panadol = $this->medicine();
        $box = $this->boxOf($panadol);

        $this->printLabels([new LabelRequest($panadol->getKey(), $box, 2)]);

        $code = (string) VariantUnit::query()->whereKey($box)->value('barcode');
        $this->assertSame(Barcode::inStoreEan13($box, 201), $code);

        $this->printLabels([new LabelRequest($panadol->getKey(), $box, 1)]);
        $this->assertSame($code, VariantUnit::query()->whereKey($box)->value('barcode'), 'Printed again: the same barcode');
    }

    public function test_a_manufacturer_barcode_is_never_replaced(): void
    {
        $this->enableLabels();
        $cetirizine = $this->medicine('Cetirizine 10 mg Tablets', [['name' => 'Box', 'factor' => 10, 'price' => 9_000, 'barcode' => '4790000000020']]);
        $cetirizine->update(['barcode' => '4790000000013']);

        $this->assertSame(0, $this->barcodes()->assignMissing([$cetirizine->refresh()], $this->owner));
        $this->printLabels([new LabelRequest($cetirizine->getKey(), null, 1), new LabelRequest($cetirizine->getKey(), $this->boxOf($cetirizine), 1)]);

        $this->assertSame('4790000000013', $cetirizine->refresh()->barcode);
        $this->assertSame('4790000000020', VariantUnit::query()->whereKey($this->boxOf($cetirizine))->value('barcode'));
    }

    public function test_a_code_already_taken_moves_to_the_next_prefix_so_barcodes_stay_unique(): void
    {
        $panadol = $this->medicine();
        $box = VariantUnit::query()->findOrFail($this->boxOf($panadol));

        // Someone typed exactly the code the box would get as another item's barcode.
        $other = $this->medicine('Amoxil 500 mg Capsules');
        $other->update(['barcode' => Barcode::inStoreEan13($box->getKey(), 201)]);

        $code = $this->barcodes()->ensureForUnit($box);

        $this->assertSame(Barcode::inStoreEan13($box->getKey(), 202), $code);
        $this->assertTrue(Barcode::isValidEan13($code));
    }

    public function test_giving_barcodes_in_bulk_covers_items_and_active_packs_all_unique(): void
    {
        $panadol = $this->medicine();
        $amoxil = $this->medicine('Amoxil 500 mg Capsules', [['name' => 'Box', 'factor' => 30, 'price' => 30_000], ['name' => 'Strip', 'factor' => 10, 'price' => 10_000]]);
        $legacy = $this->medicine('Legacy Item');
        $legacy->update(['barcode' => null]);

        $given = $this->barcodes()->assignMissing([$panadol, $amoxil, $legacy->refresh()], $this->owner);

        // Legacy's own barcode, and the box of Panadol, Legacy and Amoxil's box and strip.
        $this->assertSame(5, $given);
        $codes = [
            ...ProductVariant::query()->pluck('barcode')->all(),
            ...VariantUnit::query()->whereNotNull('barcode')->pluck('barcode')->all(),
        ];
        $this->assertSame(count($codes), count(array_unique($codes)), 'Unique across items and packs');
        $this->assertSame(Barcode::inStoreEan13($legacy->getKey(), 200), $legacy->refresh()->barcode);
        $this->assertSame(0, $this->barcodes()->assignMissing([$panadol, $amoxil, $legacy], $this->owner), 'Nothing left to give');
    }

    public function test_only_a_user_who_manages_products_or_prints_labels_gives_barcodes(): void
    {
        $this->expectException(PosException::class);

        $this->barcodes()->assignMissing([$this->medicine()], $this->cashier);
    }

    public function test_a_pack_cannot_be_given_a_barcode_an_item_already_has(): void
    {
        $panadol = $this->medicine();

        $this->expectExceptionMessage('already used by another item or pack');

        app(ProductCatalogService::class)->update($panadol->product, ['name' => $panadol->product->name], [[
            'id' => $panadol->getKey(),
            'packs' => [['id' => $this->boxOf($panadol), 'name' => 'Box', 'factor' => 10, 'barcode' => Barcode::inStoreEan13($panadol->getKey())]],
        ]], [], null, $this->owner);
    }
}
