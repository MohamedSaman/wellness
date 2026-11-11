<?php

namespace Tests\Feature\Pos;

use App\Enums\BillType;
use App\Enums\PaymentMethod;
use App\Models\SaleItem;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * The backfill of sale_items.price_changed_by (branch 14): only the lines
 * that were certainly typed (a list price and not an OPD medicine), by the
 * sale's cashier; every other line stays null, and running it again changes
 * nothing.
 */
class PriceChangedByBackfillTest extends BatchTestCase
{
    use WorksWithCheckout;

    public function test_it_fills_only_the_typed_lines_and_running_it_twice_changes_nothing(): void
    {
        $doc = $this->docTill();
        $doctor = $this->cashierAt($this->doctors);
        $fee = $this->consultation(150_000);
        $tabs = $this->medicine();
        $this->receive($tabs, 'T1', '03/2027', 100, $this->doctors);

        // An OPD bill: a typed fee (list price set) and a medicine line (list price set, but its share).
        $opd = app(CheckoutService::class)->checkout(new CheckoutData(
            $doc, $doctor,
            [new CartLine($fee->getKey(), 1, unitPriceOverride: 120_000), new CartLine($tabs->getKey(), 5)],
            [new PaymentInput(PaymentMethod::Cash, 1_000_000)],
            billType: BillType::Opd, opdMedicinesAmount: 5_000,
        ));
        // A standard bill at list prices: nothing typed.
        $standard = app(CheckoutService::class)->checkout(new CheckoutData(
            $doc, $doctor,
            [new CartLine($fee->getKey(), 1), new CartLine($tabs->getKey(), 2)],
            [new PaymentInput(PaymentMethod::Cash, 1_000_000)],
        ));

        // As every line sold before this branch: nobody recorded.
        DB::table('sale_items')->update(['price_changed_by' => null]);
        // A line already filled in (by the new code, or a first run) keeps its value.
        $kept = $opd->items[0];
        DB::table('sale_items')->where('id', $kept->getKey())->update(['price_changed_by' => $this->owner->getKey()]);

        $migration = require database_path('migrations/2026_11_11_000001_add_till_price_limits.php');

        $this->assertSame(0, $migration->backfill(), 'The only typed line was already filled.');

        DB::table('sale_items')->where('id', $kept->getKey())->update(['price_changed_by' => null]);

        $this->assertSame(1, $migration->backfill());
        $this->assertSame(0, $migration->backfill(), 'A second run changes nothing.');

        $this->assertSame((int) $doctor->getKey(), SaleItem::query()->findOrFail($kept->getKey())->price_changed_by);
        $this->assertNull(SaleItem::query()->findOrFail($opd->items[1]->getKey())->price_changed_by, 'An OPD medicine line was not typed.');
        $this->assertSame([null, null], SaleItem::query()->where('sale_id', $standard->getKey())->orderBy('id')->pluck('price_changed_by')->all());
    }

    public function test_the_migration_reports_how_many_lines_it_backfilled(): void
    {
        $fee = $this->consultation(150_000);
        $this->sell([new CartLine($fee->getKey(), 1, unitPriceOverride: 120_000)]);
        DB::table('sale_items')->update(['price_changed_by' => null]);
        $migration = require database_path('migrations/2026_11_11_000001_add_till_price_limits.php');

        Log::spy();

        $this->assertSame(1, $migration->backfillAndReport());
        $this->assertSame(0, $migration->backfillAndReport());

        Log::shouldHaveReceived('info')->with('sale_items.price_changed_by: 1 typed-price line(s) backfilled.')->once();
        Log::shouldHaveReceived('info')->with('sale_items.price_changed_by: 0 typed-price line(s) backfilled.')->once();
    }
}
