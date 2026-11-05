<?php

namespace Tests\Feature\Pos;

use App\Filament\Resources\Devices\Pages\ManageDevices;
use App\Models\AuditLog;
use App\Models\Device;
use App\Services\TillOfflineService;
use App\Support\TillSelection;
use Filament\Facades\Filament;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * Offline ready by default (docs/deploy/TILLS.md): a till the owner approved
 * prepares itself whoever is signed in; a till never approved, or revoked,
 * never does. T1 is approved here; T2 (docTill()) is not.
 */
class TillOfflineApprovalTest extends PharmacyTestCase
{
    use WorksWithCheckout;

    protected function setUp(): void
    {
        parent::setUp();

        $this->till->update(['offline_allowed' => true]);
    }

    private function prepare(Device $till, mixed $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->cashier)
            ->postJson(route('pos.till.prepare'), ['device' => $till->uuid]);
    }

    /** @param  array<string, mixed>  $query */
    private function catalog(string $token, array $query = []): TestResponse
    {
        return $this->withToken($token)->getJson(route('pos.sync.catalog', $query));
    }

    public function test_a_cashier_on_an_approved_till_gets_its_token_with_no_click(): void
    {
        $token = $this->prepare($this->till)
            ->assertCreated()
            ->assertJsonPath('till.code', 'T1')
            ->json('token');

        $this->assertSame(1, $this->till->tokens()->count());
        $this->assertStringStartsWith('auto · '.$this->cashier->name, (string) $this->till->tokens()->value('name'));
        $this->app['auth']->forgetGuards();
        $this->catalog($token)->assertOk()->assertJsonPath('till.code', 'T1');

        $entry = AuditLog::query()->where('event', TillOfflineService::REGISTERED_EVENT)->sole();
        $this->assertSame($this->cashier->getKey(), $entry->user_id);
    }

    public function test_a_till_never_approved_does_not_get_a_token(): void
    {
        $till = $this->docTill();
        $opd = $this->cashierAt($this->doctors);

        $this->assertFalse($till->offline_allowed);
        $this->prepare($till, $opd)->assertForbidden();
        $this->assertSame(0, $till->tokens()->count());
    }

    public function test_a_revoked_till_does_not_register_itself_again(): void
    {
        $this->prepare($this->till)->assertCreated();
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);

        Livewire::test(ManageDevices::class)
            ->callTableAction('revokeOfflineAccess', $this->till)
            ->assertHasNoTableActionErrors();

        $till = $this->till->fresh();
        $this->assertFalse($till->offline_allowed);
        $this->assertSame(0, $till->tokens()->count());
        $this->assertSame(1, AuditLog::query()->where('event', TillOfflineService::REVOKED_EVENT)->count());

        $this->prepare($till)->assertForbidden();
        $this->assertSame(0, $till->tokens()->count());
    }

    public function test_turning_the_approval_off_on_the_tills_form_takes_the_tokens_too(): void
    {
        $this->prepare($this->till)->assertCreated();

        $this->till->fresh()->update(['offline_allowed' => false]);

        $this->assertSame(0, $this->till->tokens()->count());
    }

    public function test_a_second_browser_is_refused_until_the_owner_resets_the_till(): void
    {
        $this->prepare($this->till)->assertCreated();
        $this->prepare($this->till)->assertConflict();

        $this->assertSame(1, $this->till->tokens()->count());
    }

    public function test_reset_frees_the_till_in_one_step_and_it_registers_itself_on_its_next_load(): void
    {
        $old = $this->prepare($this->till)->assertCreated()->json('token');
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);

        Livewire::test(ManageDevices::class)
            ->callTableAction('resetOffline', $this->till)
            ->assertHasNoTableActionErrors();

        $till = $this->till->fresh();
        $this->assertTrue($till->offline_allowed);
        $this->assertSame(0, $till->tokens()->count());

        $entry = AuditLog::query()->where('event', TillOfflineService::RESET_EVENT)->sole();
        $this->assertSame($this->owner->getKey(), $entry->user_id);
        $this->assertSame(1, $entry->new_values['tokens_revoked']);

        $new = $this->prepare($till)->assertCreated()->json('token');

        $this->app['auth']->forgetGuards();
        $this->catalog($old)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->catalog($new)->assertOk();
    }

    public function test_reset_approves_a_till_that_was_never_approved_or_was_revoked(): void
    {
        $till = $this->docTill();
        $opd = $this->cashierAt($this->doctors);
        app(TillOfflineService::class)->reset($till, $this->owner);

        $this->prepare($till->fresh(), $opd)->assertCreated();
    }

    public function test_only_the_owner_resets_or_revokes(): void
    {
        $this->actingAs($this->cashier)->get(ManageDevices::getUrl())->assertForbidden();
    }

    public function test_the_owner_pressing_set_up_at_the_till_is_the_approval(): void
    {
        $till = $this->docTill();

        $this->actingAs($this->owner)
            ->postJson(route('pos.till.register'), ['device' => $till->uuid])
            ->assertCreated();

        $this->assertTrue($till->fresh()->offline_allowed);
        $this->assertSame(1, $till->tokens()->count());
    }

    public function test_a_cashier_still_cannot_press_set_up(): void
    {
        $till = $this->docTill();
        $opd = $this->cashierAt($this->doctors);

        $this->actingAs($opd)
            ->postJson(route('pos.till.register'), ['device' => $till->uuid])
            ->assertForbidden();

        $this->assertFalse($till->fresh()->offline_allowed);
    }

    public function test_a_cashier_cannot_prepare_a_till_at_a_location_they_may_not_use(): void
    {
        $till = $this->docTill();
        $till->update(['offline_allowed' => true]);

        $this->prepare($till)->assertUnprocessable();
        $this->assertSame(0, $till->tokens()->count());
    }

    public function test_a_disabled_till_does_not_prepare_itself(): void
    {
        $this->till->update(['is_active' => false]);

        $this->prepare($this->till)->assertUnprocessable();
        $this->assertSame(0, $this->till->tokens()->count());
    }

    public function test_nothing_prepares_with_offline_sales_off(): void
    {
        Feature::for($this->shop)->deactivate('module:offline_sync');

        $this->prepare($this->till)->assertNotFound();
        $this->assertSame(0, $this->till->tokens()->count());
    }

    public function test_the_checkout_page_says_whether_this_till_is_approved(): void
    {
        $open = fn () => $this->actingAs($this->cashier)
            ->withCookie(TillSelection::COOKIE, $this->till->uuid)
            ->get(route('pos.index'));

        $open()->assertInertia(fn (Assert $page) => $page->where('offline.allowed', true));

        $this->till->update(['offline_allowed' => false]);

        $open()->assertInertia(fn (Assert $page) => $page->where('offline.allowed', false));
    }

    public function test_the_migration_approves_only_tills_that_already_have_a_token(): void
    {
        $doc = $this->docTill();
        $this->till->update(['offline_allowed' => false]);
        $doc->issueSyncToken('registered by hand');

        (require database_path('migrations/2026_11_05_000001_add_offline_allowed_to_devices.php'))->backfill();

        $this->assertTrue($doc->fresh()->offline_allowed);
        $this->assertFalse($this->till->fresh()->offline_allowed);
    }
}
