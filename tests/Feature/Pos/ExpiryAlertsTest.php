<?php

namespace Tests\Feature\Pos;

use App\Models\StockBatch;
use App\Notifications\ExpiryDigest;
use App\Services\Data\CartLine;
use App\Services\ExpiryService;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Expiry alerts (docs/PHARMACY.md §9, branch 10): the Expired and Expiring
 * soon lists per location, their boundaries (today is 2026-10-18 in
 * Colombo; the clinic warns 90 days ahead), values at batch cost, the
 * unallocated batch left out, and the daily digest to the owner.
 */
class ExpiryAlertsTest extends BatchTestCase
{
    private function expiry(): ExpiryService
    {
        return app(ExpiryService::class);
    }

    /** @return list<string> batch numbers on a list, in FEFO order */
    private function onList(string $list, ?int $locationId = null): array
    {
        return $this->expiry()->query($list, $locationId)->pluck('batch_no')->all();
    }

    public function test_the_clinic_warns_ninety_days_ahead(): void
    {
        $this->assertSame(90, $this->expiry()->warningDays());
        $this->assertSame('2026-10-18', $this->expiry()->today()->toDateString());
        $this->assertSame('2027-01-16', $this->expiry()->soonUntil()->toDateString());
    }

    public function test_boundaries_89_90_and_91_days_and_expiring_or_expired_today(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'D89', '2027-01-15', 10);
        $this->receive($panadol, 'D90', '2027-01-16', 10);
        $this->receive($panadol, 'D91', '2027-01-17', 10);
        // Usable up to and including its expiry day: today it still sells, so it is "soon".
        $this->foundExpired($panadol, 'TODAY', '2026-10-18', 5);
        // Its last day was yesterday: expired today.
        $this->foundExpired($panadol, 'YDAY', '2026-10-17', 4);

        $this->assertSame(['TODAY', 'D89', 'D90'], $this->onList(ExpiryService::SOON));
        $this->assertSame(['YDAY'], $this->onList(ExpiryService::EXPIRED));

        $days = fn (string $batchNo): ?int => $this->expiry()->daysLeft($this->batch($panadol, $batchNo));
        $this->assertSame(89, $days('D89'));
        $this->assertSame(90, $days('D90'));
        $this->assertSame(91, $days('D91'));
        $this->assertSame(0, $days('TODAY'));
        $this->assertSame(-1, $days('YDAY'));

        // The next day, TODAY has expired and D91 is inside the window.
        $this->travel(1)->days();
        $this->assertSame(['YDAY', 'TODAY'], $this->onList(ExpiryService::EXPIRED));
        $this->assertContains('D91', $this->onList(ExpiryService::SOON));
    }

    public function test_the_window_follows_the_shop_setting(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'D45', '2026-12-02', 10);
        $this->receive($panadol, 'D60', '2026-12-17', 10);

        $this->shop->update(['settings' => [...($this->shop->settings ?? []), 'near_expiry_warning_days' => 50]]);

        $this->assertSame(['D45'], $this->onList(ExpiryService::SOON));
    }

    public function test_lists_are_per_location_valued_at_batch_cost_and_leave_out_empty_and_unallocated_batches(): void
    {
        $panadol = $this->medicine();
        // Found by a count, it costs what the location's average was: the item's 1,000.
        $this->foundExpired($panadol, 'OLD', '2026-09-30', 3);
        $this->receive($panadol, 'GEN1', '2026-12-31', 30, cost: '1250.5');
        $this->receive($panadol, 'DOC1', '2026-11-30', 7, $this->doctors, cost: 900);
        $this->receive($panadol, 'EMPTY', '2026-11-15', 2);
        $this->sell([new CartLine($panadol->getKey(), 2)]); // FEFO empties EMPTY first

        // Oversold offline: the unallocated batch goes below zero but is never on a list.
        $this->sell([new CartLine($panadol->getKey(), 100)], offline: true);
        $this->assertLessThan(0, (float) $this->unallocated($panadol)?->quantity);

        $this->assertSame(['DOC1'], $this->onList(ExpiryService::SOON, (int) $this->doctors->getKey()));
        $this->assertSame([], $this->onList(ExpiryService::SOON, (int) $this->general->getKey()), 'GEN1 was sold out by the offline sale.');
        $this->assertSame(['OLD'], $this->onList(ExpiryService::EXPIRED, (int) $this->general->getKey()), 'Expired stock is never sold, so it is still there.');

        $summary = $this->expiry()->summary();
        $this->assertSame(['batches' => 1, 'value' => 3_000, 'locations' => [['location' => 'General Pharmacy', 'batches' => 1, 'value' => 3_000]]], $summary['expired']);
        $this->assertSame(['batches' => 1, 'value' => 6_300, 'locations' => [['location' => "Doctor's Medicines", 'batches' => 1, 'value' => 6_300]]], $summary['soon']);
    }

    public function test_the_value_of_a_batch_is_its_cost_times_what_it_holds_rounded_to_minor_units(): void
    {
        $panadol = $this->medicine();
        $batch = $this->receive($panadol, 'A', '2026-12-31', 3, cost: '333.3333');

        $this->assertSame(1_000, $this->expiry()->value($batch));
        $this->assertSame(1_000, $this->expiry()->summary()['soon']['value']);
    }

    public function test_the_daily_digest_goes_to_the_owner_once_a_day_with_counts_and_value(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'SOON', '2026-12-31', 10, cost: 1_000);
        $this->receive($panadol, 'SOON2', '2026-11-30', 5, $this->doctors, cost: 1_000);
        $this->foundExpired($panadol, 'OLD', '2026-09-30', 2);

        $this->artisan('pos:expiry-digest')->assertSuccessful();

        $digests = DatabaseNotification::query()->where('type', ExpiryDigest::class)->get();
        $this->assertCount(1, $digests, 'Only the owner (stock.write_off) gets it; the cashier does not.');
        $this->assertSame($this->owner->getKey(), (int) $digests[0]->notifiable_id);
        $this->assertSame('Expiry digest, 18/10/2026', $digests[0]->data['title']);
        $this->assertSame(
            "Expired: 1 batch, LKR 20.00 at cost (General Pharmacy 1). Expiring within 90 days: 2 batches, LKR 150.00 at cost (General Pharmacy 1, Doctor's Medicines 1).",
            $digests[0]->data['body'],
        );

        // The scheduler runs it every hour: still one a day.
        $this->artisan('pos:expiry-digest')->assertSuccessful();
        $this->assertSame(1, DatabaseNotification::query()->where('type', ExpiryDigest::class)->count());

        // The next day, another.
        $this->travel(1)->days();
        $this->artisan('pos:expiry-digest')->assertSuccessful();
        $this->assertSame(2, DatabaseNotification::query()->where('type', ExpiryDigest::class)->count());
    }

    public function test_the_digest_waits_for_seven_in_the_morning_at_the_shop_unless_forced(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'SOON', '2026-12-31', 10);

        // 06:00 in Colombo.
        $this->travelTo(CarbonImmutable::parse('2026-10-18 00:30:00', 'UTC'));
        $this->artisan('pos:expiry-digest')->assertSuccessful();
        $this->assertSame(0, DatabaseNotification::query()->count());

        $this->artisan('pos:expiry-digest', ['--now' => true])->assertSuccessful();
        $this->assertSame(1, DatabaseNotification::query()->count());
    }

    public function test_nothing_to_report_sends_nothing(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'LATER', '03/2028', 10);

        $this->artisan('pos:expiry-digest')->assertSuccessful();

        $this->assertSame(0, DatabaseNotification::query()->count());
    }

    public function test_the_digest_body_lists_only_what_there_is(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'SOON', '2026-12-31', 10, cost: 1_000);

        $this->artisan('pos:expiry-digest')->assertSuccessful();

        $this->assertSame(
            'Expiring within 90 days: 1 batch, LKR 100.00 at cost (General Pharmacy 1).',
            DatabaseNotification::query()->firstOrFail()->data['body'],
        );
        $this->assertSame(1, StockBatch::query()->where('is_unallocated', false)->count());
    }
}
