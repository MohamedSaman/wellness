<?php

namespace Tests\Feature\Pos\Mysql;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Models\Exchange;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Shift;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\ExchangeData;
use App\Services\Data\PaymentInput;
use App\Services\Data\ReturnLine;
use App\Services\ExchangeService;
use App\Services\ShiftService;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;

/**
 * Returns and exchanges at the till on MySQL (docs/PHARMACY.md, branch 19):
 * the same exchange sent at once by a double click or a retry is recorded
 * once, and two exchanges of the same bill line at once never take back
 * more than was sold (the bill's row lock).
 */
#[Group('mysql')]
class ExchangeMysqlTest extends MysqlTestCase
{
    private Sale $sale;

    private Shift $shift;

    private int $quarantineId;

    private ProductVariant $panadol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->panadol = $this->medicine();
        $this->receive($this->panadol, 'A', '03/2027', 100);
        $this->shift = app(ShiftService::class)->open($this->till, $this->cashier, 500_000);
        // 10 tablets at 20.00, cash.
        $this->sale = app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($this->panadol->getKey(), 10)],
            payments: [new PaymentInput(PaymentMethod::Cash, 20_000)],
        ));
        $this->quarantineId = (int) Location::query()->where('code', 'QUAR')->value('id');
    }

    private function exchange(float $quantity, string $uuid): Exchange
    {
        return app(ExchangeService::class)->create(new ExchangeData(
            $this->till,
            $this->cashier,
            $uuid,
            $this->sale->fresh(),
            [new ReturnLine((int) $this->sale->items->first()?->getKey(), $quantity, toLocationId: $this->quarantineId)],
            [],
            ReturnReason::NotEffective,
            null,
            null,
            // 2 tablets instead (40.00): the rest of the credit back in cash.
            [new CartLine($this->panadol->getKey(), 2)],
        ));
    }

    public function test_the_same_exchange_sent_at_once_is_recorded_once(): void
    {
        $uuid = (string) Str::uuid();

        $outcomes = $this->race(4, fn () => $this->exchange(5, $uuid));

        $this->assertSame(['ok', 'ok', 'ok', 'ok'], $outcomes, implode("\n", $outcomes));
        $this->assertSame([1, 1, 2], [Exchange::query()->count(), SaleReturn::query()->count(), Sale::query()->count()]);
        // 5 tablets back (100.00), 2 sold (40.00): 60.00 out of the drawer once.
        $this->assertSame(500_000 + 20_000 - 6_000, app(ShiftService::class)->expectedCash($this->shift->fresh() ?? $this->shift));
        $this->assertLedgerMatches();
    }

    public function test_two_exchanges_of_one_line_at_once_never_take_back_more_than_was_sold(): void
    {
        $outcomes = $this->race(2, fn (int $worker) => $this->exchange(6, (string) Str::uuid()));

        $this->assertSame(1, count(array_keys($outcomes, 'ok', true)), implode("\n", $outcomes));
        foreach (array_diff($outcomes, ['ok']) as $refused) {
            $this->assertStringContainsString('Only 4', $refused);
        }

        $this->assertSame(6.0, (float) $this->sale->items->first()?->fresh()?->returned_quantity);
        $this->assertSame(1, Exchange::query()->count());
        $this->assertLedgerMatches();
    }
}
