<?php

namespace Tests\Feature\Pos;

use App\Models\Device;
use App\Models\User;
use App\Models\VariantPrice;
use App\Support\TillSelection;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * The till page in a pharmacy (docs/PHARMACY.md §9, branch 8): only tills at
 * locations the cashier may use, the till's location and OPD flag, what the
 * cashier may do, and the pharmacy checkout settings with the consultation
 * item as this till sells it.
 */
class PosPagePropsTest extends PharmacyTestCase
{
    use WorksWithCheckout;

    private function page(User $user, ?Device $remembered = null): TestResponse
    {
        $request = $this->actingAs($user);
        $this->defaultCookies = []; // no till remembered from an earlier request

        if ($remembered !== null) {
            $request = $request->withCookie(TillSelection::COOKIE, $remembered->uuid);
        }

        return $request->get(route('pos.index'))->assertOk();
    }

    public function test_a_cashier_sees_only_the_tills_at_locations_they_may_use(): void
    {
        $this->docTill();

        $this->page($this->cashierAt($this->general))->assertInertia(fn (Assert $page) => $page
            ->has('tills', 1)
            ->where('tills.0.code', 'T1')
            ->where('tills.0.location', $this->general->name));

        $this->page($this->cashierAt($this->doctors))->assertInertia(fn (Assert $page) => $page
            ->has('tills', 1)
            ->where('tills.0.code', 'T2')
            ->where('tills.0.location', $this->doctors->name));

        $this->page($this->owner)->assertInertia(fn (Assert $page) => $page
            ->has('tills', 2)
            ->where('tills.0.code', 'T1')
            ->where('tills.1.code', 'T2'));
    }

    public function test_a_remembered_till_the_cashier_may_not_use_is_dropped(): void
    {
        $docTill = $this->docTill();

        $this->page($this->cashierAt($this->general), $docTill)
            ->assertInertia(fn (Assert $page) => $page->where('till', null));
    }

    public function test_the_till_carries_its_location_and_opd_flag(): void
    {
        $docTill = $this->docTill();

        $this->page($this->cashierAt($this->doctors), $docTill)->assertInertia(fn (Assert $page) => $page
            ->where('till.code', 'T2')
            ->where('till.location', ['id' => $this->doctors->getKey(), 'code' => 'DOC', 'name' => $this->doctors->name])
            ->where('till.opdBilling', true));

        $this->page($this->owner, $this->genTill())->assertInertia(fn (Assert $page) => $page
            ->where('till.code', 'T1')
            ->where('till.location.code', 'GEN')
            ->where('till.opdBilling', false));
    }

    public function test_the_page_says_what_the_cashier_may_do(): void
    {
        $this->page($this->cashier)->assertInertia(fn (Assert $page) => $page
            ->where('features.pharmacy', true)
            ->where('cashier.canOverridePrice', true)
            ->where('cashier.canOpdBill', true)
            ->where('cashier.canBatchOverride', $this->cashier->can('pos.batch_override')));

        $this->page($this->owner)->assertInertia(fn (Assert $page) => $page
            ->where('cashier.canOverridePrice', true)
            ->where('cashier.canBatchOverride', true)
            ->where('cashier.canOpdBill', true));
    }

    public function test_the_pharmacy_settings_reach_the_till(): void
    {
        $this->setShopSetting('default_prescriber', 'Dr. S. Perera');
        $this->setShopSetting('opd_receipt_medicines', 'totals');

        $this->page($this->owner, $this->genTill())->assertInertia(fn (Assert $page) => $page
            ->where('pharmacy.rxPrescriber', 'required')
            ->where('pharmacy.defaultPrescriber', 'Dr. S. Perera')
            ->where('pharmacy.controlledRegister', false)
            ->where('pharmacy.bankTransferReferenceRequired', true)
            ->where('pharmacy.opdReceiptMedicines', 'totals')
            ->where('pharmacy.opdConsultation', null));
    }

    public function test_the_consultation_item_is_presented_for_the_till(): void
    {
        $consultation = $this->consultation(175_000);

        $this->page($this->owner, $this->docTill())->assertInertia(fn (Assert $page) => $page
            ->where('pharmacy.opdConsultation.variant_id', $consultation->getKey())
            ->where('pharmacy.opdConsultation.price', 175_000)
            ->where('pharmacy.opdConsultation.allow_price_override', true)
            ->where('pharmacy.opdConsultation.track_stock', false)
            ->where('pharmacy.opdConsultation.sellable', true)
            ->where('pharmacy.opdConsultation.batches', []));

        // No till chosen yet: nothing to present it for.
        $this->page($this->owner)->assertInertia(fn (Assert $page) => $page->where('pharmacy.opdConsultation', null));
    }

    public function test_a_consultation_that_cannot_be_sold_is_not_presented(): void
    {
        $consultation = $this->consultation();
        VariantPrice::query()->where('product_variant_id', $consultation->getKey())->delete();

        $this->page($this->owner, $this->docTill())->assertInertia(fn (Assert $page) => $page->where('pharmacy.opdConsultation', null));

        $this->setShopSetting('opd_consultation_variant_id', 999_999);
        $this->page($this->owner, $this->docTill())->assertInertia(fn (Assert $page) => $page->where('pharmacy.opdConsultation', null));
    }

    public function test_without_the_pharmacy_module_there_is_no_pharmacy_block(): void
    {
        Feature::for($this->shop)->deactivate('module:pharmacy');

        $this->page($this->owner, $this->genTill())->assertInertia(fn (Assert $page) => $page
            ->where('features.pharmacy', false)
            ->where('pharmacy', null));
    }
}
