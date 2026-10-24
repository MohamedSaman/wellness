<?php

namespace Tests\Feature\Pos;

use App\Exceptions\PosException;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\LocationUser;
use App\Models\Shop;
use App\Models\User;
use App\Services\LocationService;
use App\Support\CurrentShop;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * Users' allowed locations (docs/PHARMACY.md §4.4, branch 8): rows restrict
 * the user to those locations (the owner too). With no rows, in a shop with
 * multi_location, a user with locations.all (the owner) may use every
 * location and anyone else only the shop's default location; without
 * multi_location (a retail shop), every location. Written only by
 * LocationService::setAllowedLocations().
 */
class AllowedLocationsTest extends PharmacyTestCase
{
    use WorksWithCheckout;

    private function service(): LocationService
    {
        return app(LocationService::class);
    }

    public function test_the_owner_without_allowed_locations_may_use_every_location(): void
    {
        $this->assertTrue($this->owner->can('locations.all'));
        $this->assertSame([], $this->owner->allowedLocationIds());
        $this->assertNull($this->owner->usableLocationIds());
        $this->assertTrue($this->owner->mayUseLocation($this->general));
        $this->assertTrue($this->owner->mayUseLocation($this->doctors));
        $this->assertTrue($this->owner->mayUseLocation((int) $this->doctors->getKey()));
    }

    public function test_a_cashier_without_allowed_locations_may_use_only_the_default_location(): void
    {
        $this->assertTrue($this->general->is_default);
        $this->assertFalse($this->cashier->can('locations.all'));
        $this->assertSame([], $this->cashier->allowedLocationIds());
        $this->assertSame([(int) $this->general->getKey()], $this->cashier->usableLocationIds());
        $this->assertTrue($this->cashier->mayUseLocation($this->general));
        $this->assertFalse($this->cashier->mayUseLocation($this->doctors));
        $this->assertFalse($this->cashier->mayUseLocation((int) $this->doctors->getKey()));

        // Without a current shop too: the shop is read by the user's shop_id.
        $this->useShop(null);
        $fresh = User::query()->whereKey($this->cashier->getKey())->firstOrFail();
        $this->assertSame([(int) $this->general->getKey()], $fresh->usableLocationIds());
    }

    public function test_the_default_follows_the_shops_default_location(): void
    {
        app(LocationService::class)->update($this->doctors, [...$this->doctors->only(['name', 'code', 'address', 'is_active', 'is_sellable', 'sort_order']), 'is_default' => true], $this->owner);

        $fresh = User::query()->whereKey($this->cashier->getKey())->firstOrFail();
        $this->assertSame([(int) $this->doctors->getKey()], $fresh->usableLocationIds());
    }

    public function test_the_owner_with_allowed_locations_is_limited_to_them(): void
    {
        $this->service()->setAllowedLocations($this->owner, [(int) $this->doctors->getKey()], $this->owner);

        $this->assertSame([(int) $this->doctors->getKey()], $this->owner->usableLocationIds());
        $this->assertTrue($this->owner->mayUseLocation($this->doctors));
        $this->assertFalse($this->owner->mayUseLocation($this->general));
    }

    public function test_a_cashier_given_locations_all_may_use_every_location(): void
    {
        $this->cashier->givePermissionTo('locations.all');
        $this->cashier->forgetUsableLocations();

        $this->assertNull($this->cashier->usableLocationIds());
        $this->assertTrue($this->cashier->mayUseLocation($this->doctors));

        // Rows still limit them.
        $this->service()->setAllowedLocations($this->cashier, [(int) $this->doctors->getKey()], $this->owner);
        $this->assertFalse($this->cashier->mayUseLocation($this->general));
    }

    public function test_allowed_locations_restrict_the_user_to_them(): void
    {
        $this->service()->setAllowedLocations($this->cashier, [(int) $this->doctors->getKey()], $this->owner);

        $this->assertSame([(int) $this->doctors->getKey()], $this->cashier->usableLocationIds());
        $this->assertTrue($this->cashier->mayUseLocation($this->doctors));
        $this->assertTrue($this->cashier->mayUseLocation((int) $this->doctors->getKey()));
        $this->assertFalse($this->cashier->mayUseLocation($this->general));
        $this->assertFalse($this->cashier->mayUseLocation((int) $this->general->getKey()));

        // A fresh instance reads the same rows; the relation lists them.
        $fresh = User::query()->whereKey($this->cashier->getKey())->firstOrFail();
        $this->assertFalse($fresh->mayUseLocation($this->general));
        $this->assertSame(['DOC'], $fresh->allowedLocations()->pluck('code')->all());
        $this->assertSame($this->shop->getKey(), LocationUser::query()->firstOrFail()->shop_id);
    }

    public function test_the_list_is_read_once_per_instance(): void
    {
        $this->assertTrue($this->cashier->mayUseLocation($this->general));

        // Rows written behind this instance's back are not seen until it forgets its list.
        LocationUser::query()->create(['user_id' => $this->cashier->getKey(), 'location_id' => $this->doctors->getKey()]);
        $this->assertTrue($this->cashier->mayUseLocation($this->general));

        $this->cashier->forgetUsableLocations();
        $this->assertFalse($this->cashier->mayUseLocation($this->general));
    }

    public function test_the_restriction_holds_without_a_current_shop(): void
    {
        $this->service()->setAllowedLocations($this->cashier, [(int) $this->doctors->getKey()], $this->owner);
        $this->useShop(null);

        $user = User::query()->whereKey($this->cashier->getKey())->firstOrFail();

        $this->assertFalse($user->mayUseLocation($this->general));
    }

    public function test_setting_them_replaces_the_rows_and_an_empty_list_clears_them(): void
    {
        $this->service()->setAllowedLocations($this->cashier, [(int) $this->general->getKey()], $this->owner);
        $this->service()->setAllowedLocations($this->cashier, [(int) $this->doctors->getKey(), (int) $this->general->getKey()], $this->owner);

        $this->assertSame([(int) $this->general->getKey(), (int) $this->doctors->getKey()], $this->cashier->usableLocationIds());
        $this->assertSame(2, LocationUser::query()->count());

        $this->service()->setAllowedLocations($this->cashier, [], $this->owner);

        // Back to no rows: the default location only for a cashier.
        $this->assertSame([], $this->cashier->allowedLocationIds());
        $this->assertSame([(int) $this->general->getKey()], $this->cashier->usableLocationIds());
        $this->assertTrue($this->cashier->mayUseLocation($this->general));
        $this->assertFalse($this->cashier->mayUseLocation($this->doctors));
        $this->assertSame(0, LocationUser::query()->count());
    }

    public function test_changes_are_audited_with_the_old_and_new_ids(): void
    {
        $gen = (int) $this->general->getKey();
        $doc = (int) $this->doctors->getKey();

        $this->service()->setAllowedLocations($this->cashier, [$doc], $this->owner);
        $this->service()->setAllowedLocations($this->cashier, [$doc, $gen], $this->owner);
        // No change, no entry.
        $this->service()->setAllowedLocations($this->cashier, [$gen, $doc], $this->owner);

        $entries = AuditLog::query()->where('event', 'allowed_locations_changed')->orderBy('id')->get();

        $this->assertCount(2, $entries);
        $this->assertSame($this->owner->getKey(), $entries[0]->user_id);
        $this->assertSame($this->cashier->getKey(), $entries[0]->auditable_id);
        $this->assertSameIgnoringKeyOrder(['location_ids' => []], $entries[0]->old_values);
        $this->assertSameIgnoringKeyOrder(['location_ids' => [$doc]], $entries[0]->new_values);
        $this->assertSameIgnoringKeyOrder(['location_ids' => [$doc]], $entries[1]->old_values);
        $this->assertSameIgnoringKeyOrder(['location_ids' => [$gen, $doc]], $entries[1]->new_values);
    }

    public function test_it_needs_users_manage(): void
    {
        $this->expectException(PosException::class);
        $this->expectExceptionMessage('You do not have permission to manage users.');

        $this->service()->setAllowedLocations($this->cashier, [(int) $this->doctors->getKey()], $this->cashier);
    }

    public function test_only_active_locations_of_the_shop_are_accepted(): void
    {
        $inactive = Location::query()->create(['name' => 'Old store', 'code' => 'OLD', 'is_active' => false]);

        $other = Shop::query()->create(['name' => 'Other', 'code' => 'OTHER', 'business_type' => 'pharmacy']);
        $foreign = app(CurrentShop::class)->runAs($other, fn (): Location => Location::query()->create(['name' => 'Elsewhere', 'code' => 'ELSE']));

        foreach ([$inactive->getKey(), $foreign->getKey(), 999_999] as $id) {
            try {
                $this->service()->setAllowedLocations($this->cashier, [(int) $this->doctors->getKey(), (int) $id], $this->owner);
                $this->fail("Location {$id} was accepted.");
            } catch (PosException $e) {
                $this->assertSame('Choose active locations of this shop.', $e->getMessage());
            }
        }

        $this->assertSame(0, LocationUser::query()->count());
        $this->assertSame([], $this->cashier->allowedLocationIds());
    }

    public function test_a_user_of_another_shop_is_refused(): void
    {
        $stranger = new User;
        $stranger->forceFill(['name' => 'Stranger', 'email' => 'stranger@example.test', 'password' => 'password'])->save();

        $this->expectException(PosException::class);
        $this->expectExceptionMessage('Choose a user of this shop.');

        $this->service()->setAllowedLocations($stranger, [(int) $this->doctors->getKey()], $this->owner);
    }

    public function test_the_checkout_helpers_build_the_two_counters(): void
    {
        $opd = $this->cashierAt($this->doctors);
        $both = $this->cashierAt($this->general, $this->doctors);
        $atDefault = $this->cashierAt();

        $this->assertTrue($opd->mayUseLocation($this->doctors));
        $this->assertFalse($opd->mayUseLocation($this->general));
        $this->assertTrue($opd->can('pos.opd_bill'));
        $this->assertTrue($both->mayUseLocation($this->general));
        $this->assertSame([(int) $this->general->getKey()], $atDefault->usableLocationIds());

        $till = $this->docTill();
        $this->assertSame('T2', $till->code);
        $this->assertSame($this->doctors->getKey(), $till->location_id);
        $this->assertTrue($till->fresh()?->opd_billing);
        $this->assertFalse($this->docTill(false)->fresh()?->opd_billing);
        $this->assertSame($this->general->getKey(), $this->genTill()->location_id);
    }
}
