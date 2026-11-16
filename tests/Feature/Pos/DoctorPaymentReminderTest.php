<?php

namespace Tests\Feature\Pos;

use App\Enums\CashMovementType;
use App\Filament\Pages\ShopSettings;
use App\Models\Doctor;
use App\Services\CashMovementService;
use App\Services\ShiftService;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * The shop's cash settings (docs/PHARMACY.md, branch 18): "Remind about
 * doctor payment at shift close" (off by default) reaches the till, and a
 * shift closes with or without a doctor paid whatever it says; the limits
 * per role are kept in the shop's settings, blank for no limit.
 */
class DoctorPaymentReminderTest extends PharmacyTestCase
{
    use WorksWithCheckout;

    public function test_the_reminder_is_off_by_default_and_reaches_the_till_when_on(): void
    {
        app(ShiftService::class)->open($this->till, $this->cashier, 0);

        $this->actingAs($this->cashier)->withCookie('pos_till', $this->till->uuid)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('cashMovements.remindDoctorPayment', false));

        $this->setShopSetting('doctor_payment_reminder', true);

        $this->actingAs($this->cashier)->withCookie('pos_till', $this->till->uuid)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('cashMovements.remindDoctorPayment', true));
    }

    public function test_closing_is_never_blocked_with_or_without_a_doctor_paid(): void
    {
        $this->setShopSetting('doctor_payment_reminder', true);
        $shifts = app(ShiftService::class);

        // No doctor paid: the figures say so (an empty list) and the shift closes.
        $shifts->open($this->till, $this->cashier, 10_000);
        $this->actingAs($this->cashier)
            ->getJson(route('pos.shift.expected', ['device' => $this->till->uuid]))
            ->assertJsonPath('summary.doctor_payments', []);
        $this->actingAs($this->cashier)
            ->postJson(route('pos.shift.close'), ['device' => $this->till->uuid, 'counted_cash' => '100'])
            ->assertOk()
            ->assertJsonPath('closed.difference', 0);

        // A doctor paid: it closes the same way.
        $shifts->open($this->till, $this->cashier, 10_000);
        app(CashMovementService::class)->create($this->till, $this->cashier, CashMovementType::DoctorPayment, 5_000, doctor: Doctor::query()->create(['name' => 'Dr. Perera']));
        $this->actingAs($this->cashier)
            ->postJson(route('pos.shift.close'), ['device' => $this->till->uuid, 'counted_cash' => '50'])
            ->assertOk()
            ->assertJsonPath('closed.expected_cash', 5_000);
    }

    public function test_the_owner_sets_the_limits_and_the_reminder_in_shop_settings(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ShopSettings::class)
            ->assertFormSet([
                'cash_limits.cashier.paid_out' => '2000.00',
                'cash_limits.cashier.doctor_payment' => '25000.00',
                'cash_limits.owner.paid_out' => null,
                'doctor_payment_reminder' => false,
            ])
            ->assertSee('Remind about doctor payment at shift close')
            ->fillForm([
                'cash_limits.cashier.paid_out' => '1500',
                'cash_limits.cashier.doctor_payment' => '',
                'cash_limits.owner.paid_out' => '100000',
                'doctor_payment_reminder' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $shop = $this->shop->fresh();
        $this->assertSame([
            'cashier' => ['paid_out' => 150_000, 'doctor_payment' => null],
            'owner' => ['paid_out' => 10_000_000, 'doctor_payment' => null],
        ], $shop?->setting('cash_movement_limits'));
        $this->assertTrue($shop?->setting('doctor_payment_reminder'));

        $movements = app(CashMovementService::class);
        $this->assertSame(150_000, $movements->limitFor($this->cashier, CashMovementType::PaidOut));
        $this->assertNull($movements->limitFor($this->cashier, CashMovementType::DoctorPayment));
        $this->assertSame(10_000_000, $movements->limitFor($this->owner, CashMovementType::PaidOut));

        Livewire::test(ShopSettings::class)
            ->fillForm(['cash_limits.cashier.paid_out' => 'lots'])
            ->call('save')
            ->assertHasFormErrors(['cash_limits.cashier.paid_out']);
    }
}
