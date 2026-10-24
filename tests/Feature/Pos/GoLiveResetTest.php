<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\GoodsReceipt;
use App\Models\InvoiceNumberBlock;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Shift;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupService;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\ReceiptLine;
use App\Services\Data\ReturnLine;
use App\Services\ReturnService;
use App\Services\ShiftService;
use Illuminate\Testing\PendingCommand;
use Mockery\MockInterface;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * pos:go-live-reset (docs/deploy/GO-LIVE.md): the rehearsal's sales,
 * returns, voids, shifts, their stock movements and the invoice numbering
 * go; products, suppliers, users, settings and the stock received stay,
 * with the levels and batches as the ledger without the sales says. With
 * --include-stock all stock and supplier documents go too. An owner signs it
 * off twice, a backup comes first, and nothing happens otherwise.
 */
class GoLiveResetTest extends BatchTestCase
{
    use WorksWithPurchasing;

    private function expectBackup(): void
    {
        $this->mock(BackupService::class, fn (MockInterface $mock) => $mock->shouldReceive('create')->once()->with('pre-go-live')->andReturn('pos-backup-20261018-043000-pre-go-live.tar.gz.age'));
    }

    private function rehearse(): array
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'B1', '2027-12-31', 100);

        app(ShiftService::class)->open($this->till, $this->cashier, 500_000);
        $kept = $this->sell([new CartLine($panadol->getKey(), 6)]);
        $returned = $this->sell([new CartLine($panadol->getKey(), 4)]);
        app(ReturnService::class)->processLines($returned, [new ReturnLine((int) $returned->items()->value('id'), 2)], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        $voided = $this->sell([new CartLine($panadol->getKey(), 3)]);
        app(CheckoutService::class)->void($voided, $this->owner, 'Rehearsal');
        $this->till->issueSyncToken('rehearsal');

        return [$panadol, $kept];
    }

    private function reset(array $options = ['--shop' => 'CLINIC']): PendingCommand
    {
        return $this->artisan('pos:go-live-reset', $options)
            ->expectsQuestion('Owner email', 'owner@clinic.test')
            ->expectsQuestion('Owner password', 'password')
            ->expectsQuestion('Type the shop code (CLINIC) to continue', 'CLINIC')
            ->expectsQuestion('Type RESET to remove the test transactions now', 'RESET');
    }

    public function test_the_reset_removes_the_test_transactions_and_keeps_the_master_data_and_stock_received(): void
    {
        [$panadol] = $this->rehearse();
        $this->expectBackup();
        $products = Product::query()->count();
        $suppliers = Supplier::query()->count();
        $users = User::query()->count();
        $settings = $this->shop->fresh()?->settings;
        $this->assertSame(92.0, $panadol->stockAt($this->general));

        $this->reset()->assertSuccessful();

        foreach ([Sale::class, SaleReturn::class, Shift::class, InvoiceNumberBlock::class] as $model) {
            $this->assertSame(0, $model::query()->count(), $model);
        }

        $this->assertSame(0, StockMovement::query()->whereIn('reference_type', [(new Sale)->getMorphClass(), (new SaleReturn)->getMorphClass()])->count());
        $this->assertSame($products, Product::query()->count());
        $this->assertSame($suppliers, Supplier::query()->count());
        $this->assertSame($users, User::query()->count());
        $this->assertSameIgnoringKeyOrder($settings, $this->shop->fresh()?->settings);

        // What was received stays; what the sales took is back, in the level, the batch and the running balance.
        $this->assertSame(100.0, $panadol->stockAt($this->general));
        $this->assertSame(['B1' => 100.0], $this->batchQuantities($panadol));
        $this->assertSame('100.000', (string) StockMovement::query()->where('product_variant_id', $panadol->getKey())->orderByDesc('id')->value('balance_after'));
        $this->assertLedgerMatches();

        // Every till starts again at 000001 and must be registered again for offline sales.
        $till = Device::query()->where('code', 'T1')->firstOrFail();
        $this->assertSame(1, $till->next_invoice_number);
        $this->assertSame(0, $till->tokens()->count());
        $this->assertSame('T1-000001', $this->sell([new CartLine($panadol->getKey(), 1)])->invoice_no);

        $this->assertDatabaseHas('audit_logs', ['event' => 'go_live_reset', 'user_id' => $this->owner->getKey()]);
        $this->assertSame(0, AuditLog::query()->where('auditable_type', (new Sale)->getMorphClass())->where('event', '!=', 'go_live_reset')->count());
    }

    public function test_include_stock_removes_all_stock_and_supplier_documents_too(): void
    {
        [$panadol] = $this->rehearse();
        $this->postReceipt($this->supplier(), [new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: 'G1', expiry: '12/2027')]);
        $this->assertSame(1, GoodsReceipt::query()->count());
        $this->expectBackup();
        $parLevels = StockLevel::query()->count();

        $this->reset(['--shop' => 'CLINIC', '--include-stock' => true])->assertSuccessful();

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, StockBatch::query()->count());
        $this->assertSame(0, GoodsReceipt::query()->count());
        $this->assertSame(0.0, (float) StockLevel::query()->sum('quantity'));
        $this->assertSame($parLevels, StockLevel::query()->count(), 'Levels (with their reorder and par levels) stay, at zero.');
        $this->assertGreaterThan(0, Supplier::query()->count());
        $this->assertGreaterThan(0, Product::query()->count());
    }

    public function test_the_reset_refuses_without_both_confirmations_or_an_owner(): void
    {
        $this->rehearse();
        $this->mock(BackupService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));
        $sales = Sale::query()->count();

        $this->artisan('pos:go-live-reset', ['--shop' => 'CLINIC'])
            ->expectsQuestion('Owner email', 'owner@clinic.test')
            ->expectsQuestion('Owner password', 'password')
            ->expectsQuestion('Type the shop code (CLINIC) to continue', 'CLINIC')
            ->expectsQuestion('Type RESET to remove the test transactions now', 'reset')
            ->assertFailed();

        $this->artisan('pos:go-live-reset', ['--shop' => 'CLINIC'])
            ->expectsQuestion('Owner email', 'cashier@clinic.test')
            ->expectsQuestion('Owner password', 'password')
            ->assertFailed();

        $this->artisan('pos:go-live-reset', ['--shop' => 'CLINIC'])
            ->expectsQuestion('Owner email', 'owner@clinic.test')
            ->expectsQuestion('Owner password', 'wrong')
            ->assertFailed();

        $this->artisan('pos:go-live-reset', ['--shop' => 'CLINIC', '--no-interaction' => true])->assertFailed();

        $this->assertSame($sales, Sale::query()->count());
    }

    public function test_nothing_is_removed_when_the_backup_fails(): void
    {
        $this->rehearse();
        $this->mock(BackupService::class, fn (MockInterface $mock) => $mock->shouldReceive('create')->andThrow(new BackupException('No backup recipients.')));
        $sales = Sale::query()->count();

        $this->reset()->assertFailed();

        $this->assertSame($sales, Sale::query()->count());
    }

    public function test_a_till_still_holding_offline_sales_stops_the_reset(): void
    {
        $this->rehearse();
        $this->till->forceFill(['pending_uploads' => 3])->saveQuietly();
        $this->mock(BackupService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));

        $this->artisan('pos:go-live-reset', ['--shop' => 'CLINIC'])->assertFailed();

        $this->assertGreaterThan(0, Sale::query()->count());
    }
}
