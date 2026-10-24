<?php

namespace Tests\Feature\Pos;

use App\Enums\ImportExisting;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\PayableStatus;
use App\Models\Supplier;
use App\Models\SupplierOpeningBalance;
use App\Models\SupplierPayable;
use App\Services\ModuleService;
use App\Services\SupplierPayablesService;
use App\Services\SupplierService;
use Tests\Feature\Pos\Concerns\WorksWithImports;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * Importing suppliers and their opening invoices into the demo clinic
 * (today 2026-10-18): suppliers matched by name (skipped or updated from
 * the filled-in cells), unpaid invoices at go-live as opening invoices in
 * supplier payables, due on the invoice date + the supplier's terms unless
 * a due date is given, and never entered twice.
 */
class ImportSuppliersTest extends BatchTestCase
{
    use WorksWithImports, WorksWithPurchasing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImportStorage();

        $this->actingAs($this->owner);
    }

    public function test_suppliers_import_with_every_field(): void
    {
        $run = $this->import(ImportType::Suppliers, $this->templateFile(ImportType::Suppliers, [
            ['name' => 'Hemas Pharmaceuticals', 'contact_name' => 'N. Perera', 'phone' => '0112345678', 'email' => 'orders@hemas.lk', 'address' => 'Colombo 2', 'tax_number' => '114567890-7000', 'payment_terms_days' => 30, 'notes' => 'Tuesdays', 'is_active' => 'Yes'],
            ['name' => 'State Pharmaceuticals', 'payment_terms_days' => 45],
            ['name' => 'Old Supplier', 'is_active' => 'No'],
        ]));

        $this->assertImported($run);
        $this->assertSame(3, $run->result_counts['created']);

        $hemas = Supplier::query()->where('name', 'Hemas Pharmaceuticals')->sole();
        $this->assertSame(['N. Perera', '0112345678', 'orders@hemas.lk', 'Colombo 2', '114567890-7000', 30, 'Tuesdays', true], [
            $hemas->contact_name, $hemas->phone, $hemas->email, $hemas->address, $hemas->tax_number, $hemas->payment_terms_days, $hemas->notes, $hemas->is_active,
        ]);
        $this->assertSame(45, Supplier::query()->where('name', 'State Pharmaceuticals')->value('payment_terms_days'));
        $this->assertFalse(Supplier::query()->where('name', 'Old Supplier')->sole()->is_active);
    }

    public function test_supplier_errors_and_matching_by_name(): void
    {
        $this->supplier('Hemas Pharmaceuticals', ['phone' => '011 000', 'payment_terms_days' => 30]);

        $file = $this->templateFile(ImportType::Suppliers, [
            ['name' => 'HEMAS  pharmaceuticals', 'email' => 'accounts@hemas.lk', 'payment_terms_days' => 60],
            ['name' => 'Bad Email Ltd', 'email' => 'not-an-email'],
            ['name' => 'Long Terms Ltd', 'payment_terms_days' => 400],
            ['name' => 'Twice Ltd'],
            ['name' => 'twice ltd'],
            ['name' => null, 'phone' => '0771234567'],
        ]);

        $preview = $this->preview(ImportType::Suppliers, $file);
        $this->assertSame(['Hemas Pharmaceuticals is already on file: skipped.'], $this->issuesOf($preview, 2)['warnings']);
        $this->assertSame(['Email: "not-an-email" is not an email address.'], $this->issuesOf($preview, 3)['errors']);
        $this->assertSame(['Payment terms (days) must be a whole number from 0 to 365.'], $this->issuesOf($preview, 4)['errors']);
        $this->assertSame(['twice ltd is also on row 5: list each supplier once.'], $this->issuesOf($preview, 6)['errors']);
        $this->assertSame(['Supplier name is missing.'], $this->issuesOf($preview, 7)['errors']);

        // Update: only the filled-in cells change it.
        $ok = $this->templateFile(ImportType::Suppliers, [['name' => 'HEMAS  pharmaceuticals', 'email' => 'accounts@hemas.lk', 'payment_terms_days' => 60]]);
        $this->assertImported($this->import(ImportType::Suppliers, $ok, ImportExisting::Update));

        $hemas = Supplier::query()->sole();
        $this->assertSame(['Hemas Pharmaceuticals', '011 000', 'accounts@hemas.lk', 60], [$hemas->name, $hemas->phone, $hemas->email, $hemas->payment_terms_days]);
    }

    public function test_opening_invoices_become_payables_due_after_the_suppliers_terms(): void
    {
        $hemas = $this->supplier('Hemas Pharmaceuticals', ['payment_terms_days' => 30]);
        $spc = $this->supplier('State Pharmaceuticals', ['payment_terms_days' => 45]);

        $run = $this->import(ImportType::OpeningInvoices, $this->templateFile(ImportType::OpeningInvoices, [
            ['supplier' => 'Hemas Pharmaceuticals', 'invoice_no' => 'HPL-1001', 'invoice_date' => '15/09/2026', 'amount' => '45,250.00'],
            ['supplier' => 'hemas pharmaceuticals', 'invoice_no' => 'HPL-1002', 'invoice_date' => '2026-10-01', 'amount' => 18900.5, 'due_on' => '15/11/2026', 'note' => 'Agreed later'],
            ['supplier' => 'State Pharmaceuticals', 'invoice_no' => 'SPC-88', 'invoice_date' => '10/10/2026', 'amount' => 7600],
        ]));

        $this->assertImported($run);
        $this->assertSame(3, $run->result_counts['created']);

        $first = SupplierOpeningBalance::query()->where('invoice_no', 'HPL-1001')->sole();
        $this->assertSame([4_525_000, '2026-09-15', '2026-10-15', '2026-10-18'], [$first->amount, $first->invoice_date->toDateString(), $first->due_on->toDateString(), $first->as_of->toDateString()]);
        $this->assertSame('Imported from import.xlsx', $first->note);
        $this->assertSame('2026-11-15', SupplierOpeningBalance::query()->where('invoice_no', 'HPL-1002')->value('due_on')?->toDateString());
        $this->assertSame('Agreed later', SupplierOpeningBalance::query()->where('invoice_no', 'HPL-1002')->value('note'));

        $payables = SupplierPayable::query()->orderBy('id')->get();
        $this->assertSame([4_525_000, 1_890_050, 760_000], $payables->pluck('amount')->all());
        $this->assertSame(['2026-10-15', '2026-11-15', '2026-11-24'], $payables->map(fn (SupplierPayable $p): string => $p->due_on->toDateString())->all());
        $this->assertTrue($payables->every(fn (SupplierPayable $p): bool => $p->status === PayableStatus::Unpaid));

        $this->assertSame(6_415_050, app(SupplierService::class)->balance($hemas)['balance']);
        $this->assertSame(0, app(SupplierService::class)->reconcile($hemas)['difference']);
        $this->assertSame(760_000, app(SupplierService::class)->balance($spc)['balance']);
    }

    public function test_aging_overdue_and_statements_use_the_invoices_own_dates_not_the_import_day(): void
    {
        $cash = $this->supplier('Cash Traders', ['payment_terms_days' => 0]);
        $this->supplier('Credit Traders', ['payment_terms_days' => 30]);

        // Dated 60 days ago (19/08/2026): due that day with no terms, so 60 days overdue;
        // with 30 days' terms, due 18/09, so 30 days overdue; a due date given wins.
        $this->assertImported($this->import(ImportType::OpeningInvoices, $this->templateFile(ImportType::OpeningInvoices, [
            ['supplier' => 'Cash Traders', 'invoice_no' => 'C-60', 'invoice_date' => '19/08/2026', 'amount' => '600.00'],
            ['supplier' => 'Credit Traders', 'invoice_no' => 'K-60', 'invoice_date' => '19/08/2026', 'amount' => '300.00'],
            ['supplier' => 'Credit Traders', 'invoice_no' => 'K-DUE', 'invoice_date' => '19/08/2026', 'amount' => '100.00', 'due_on' => '01/11/2026'],
        ])));

        $payables = app(SupplierPayablesService::class);
        $today = $payables->today();
        $c60 = SupplierPayable::query()->where('invoice_no', 'C-60')->sole();
        $k60 = SupplierPayable::query()->where('invoice_no', 'K-60')->sole();
        $kDue = SupplierPayable::query()->where('invoice_no', 'K-DUE')->sole();

        // Only the entry is dated the import day.
        $this->assertSame(['2026-08-19', '2026-08-19', '2026-10-18'], [$c60->invoice_date->toDateString(), $c60->due_on->toDateString(), $c60->document_date->toDateString()]);
        $this->assertSame('2026-10-18', SupplierOpeningBalance::query()->where('invoice_no', 'C-60')->value('as_of')->toDateString());
        $this->assertSame(['2026-09-18', '2026-11-01'], [$k60->due_on->toDateString(), $kDue->due_on->toDateString()]);

        // Aging and overdue run from those due dates.
        $this->assertSame([60, 30, 0], [$c60->daysOverdue($today), $k60->daysOverdue($today), $kDue->daysOverdue($today)]);
        $this->assertSame(['current' => 10_000, '1_30' => 30_000, '31_60' => 60_000, '61_90' => 0, 'over_90' => 0, 'total' => 100_000], $payables->aging());
        $this->assertSame([$c60->getKey()], $payables->whereBucket(SupplierPayable::query(), '31_60')->pluck('id')->all());
        $this->assertSame(['count' => 2, 'amount' => 90_000], $payables->dueSoon()['overdue']);

        // The statement lists it at its own invoice date, with the import day as a detail, so the
        // balance brought forward matches the supplier's; the unpaid list has the file's due date.
        $line = $payables->statement($cash, '2026-08-01', '2026-10-18')['lines'][0];
        $this->assertSame(['19/08/2026', '19/08/2026', 'Opening invoice C-60', 'Imported from import.xlsx · Entered 18/10/2026', 60_000], [$line['date']->format('d/m/Y'), $line['invoice_date']?->format('d/m/Y'), $line['document'], $line['detail'], $line['debit']]);
        $october = $payables->statement($cash, '2026-10-01', '2026-10-18');
        $this->assertSame([60_000, [], 60_000], [$october['opening'], $october['lines'], $october['closing']]);

        $this->get(route('purchasing.print.statement', ['supplier' => $cash, 'from' => '2026-08-01', 'to' => '2026-10-18']))
            ->assertOk()
            ->assertSeeInOrder(['19/08/2026', '19/08/2026', 'Opening invoice C-60', 'Entered 18/10/2026', 'Unpaid invoices at 18/10/2026', 'C-60', '19/08/2026', '19/08/2026', '60']);
    }

    public function test_opening_invoice_errors_and_running_the_same_file_again(): void
    {
        $hemas = $this->supplier('Hemas Pharmaceuticals', ['payment_terms_days' => 30]);
        $rows = [
            ['supplier' => 'Hemas Pharmaceuticals', 'invoice_no' => 'HPL-1001', 'invoice_date' => '15/09/2026', 'amount' => '45250'],
        ];

        $this->assertImported($this->import(ImportType::OpeningInvoices, $this->templateFile(ImportType::OpeningInvoices, $rows)));

        $preview = $this->preview(ImportType::OpeningInvoices, $this->templateFile(ImportType::OpeningInvoices, [
            ...$rows,
            ['supplier' => 'Unknown Traders', 'invoice_no' => 'U-1', 'invoice_date' => '01/10/2026', 'amount' => 100],
            ['supplier' => 'Hemas Pharmaceuticals', 'invoice_no' => 'HPL-2', 'invoice_date' => '01/12/2026', 'amount' => 100],
            ['supplier' => 'Hemas Pharmaceuticals', 'invoice_no' => 'HPL-3', 'invoice_date' => '01/10/2026', 'amount' => 0],
            ['supplier' => 'Hemas Pharmaceuticals', 'invoice_no' => 'HPL-4', 'invoice_date' => '01/10/2026', 'amount' => 100, 'due_on' => '01/09/2026'],
            ['supplier' => 'Hemas Pharmaceuticals', 'invoice_no' => 'HPL-5', 'invoice_date' => '31/02/2026', 'amount' => 100],
            ['supplier' => 'Hemas Pharmaceuticals', 'invoice_no' => 'HPL-6', 'invoice_date' => '01/08/2026', 'amount' => 100],
            ['supplier' => 'Hemas Pharmaceuticals', 'invoice_no' => 'hpl-6', 'invoice_date' => '01/08/2026', 'amount' => 100],
        ]));

        $this->assertSame(['Opening invoice HPL-1001 of Hemas Pharmaceuticals is already entered: skipped.'], $this->issuesOf($preview, 2)['warnings']);
        $this->assertStringContainsString('Unknown supplier "Unknown Traders"', implode(' ', $this->issuesOf($preview, 3)['errors']));
        $this->assertSame(['Invoice date 01/12/2026 is in the future.'], $this->issuesOf($preview, 4)['errors']);
        $this->assertSame(['Amount owed must be more than zero.'], $this->issuesOf($preview, 5)['errors']);
        $this->assertSame(['Due date 01/09/2026 is before the invoice date.'], $this->issuesOf($preview, 6)['errors']);
        $this->assertSame(['Invoice date: "31/02/2026" is not a date such as 15/10/2026.'], $this->issuesOf($preview, 7)['errors']);
        $this->assertSame(['Due on 31/08/2026 (invoice date + 30 days): already overdue.'], $this->issuesOf($preview, 8)['warnings']);
        $this->assertSame(['Invoice hpl-6 of Hemas Pharmaceuticals is also on row 8.'], $this->issuesOf($preview, 9)['errors']);

        $this->assertSame(1, SupplierOpeningBalance::query()->count());
        $this->assertSame(4_525_000, app(SupplierService::class)->balance($hemas)['balance']);
    }

    public function test_opening_invoices_need_the_purchasing_module(): void
    {
        app(ModuleService::class)->disable($this->shop, 'pharmacy');
        app(ModuleService::class)->disable($this->shop, 'purchasing');

        $this->assertArrayNotHasKey('opening_invoices', ImportType::options($this->shop));

        $this->expectExceptionMessage('Supplier opening invoices can only be imported with the purchasing module on.');
        $this->imports()->template(ImportType::OpeningInvoices, $this->owner);
    }

    public function test_an_invoice_entered_by_hand_after_the_check_is_skipped_not_entered_twice(): void
    {
        $this->supplier('Hemas Pharmaceuticals');
        $preview = $this->preview(ImportType::OpeningInvoices, $this->templateFile(ImportType::OpeningInvoices, [
            ['supplier' => 'Hemas Pharmaceuticals', 'invoice_no' => 'HPL-1', 'invoice_date' => '01/10/2026', 'amount' => 100],
        ]));

        // Entered by hand between the check and the write: the write checks again and skips it.
        app(SupplierService::class)->recordOpeningBalance(Supplier::query()->sole(), 10_000, $this->owner, invoiceNo: 'HPL-1', invoiceDate: '2026-10-01');
        $run = $this->imports()->confirm($preview->run, $this->owner)->refresh();
        $this->assertSame(ImportStatus::Completed, $run->status);
        $this->assertSameIgnoringKeyOrder(['rows' => 1, 'created' => 0, 'updated' => 0, 'skipped' => 1, 'errors' => 0, 'warnings' => 1, 'expiry_months' => 0], $run->result_counts);
        $this->assertSame(1, SupplierOpeningBalance::query()->count());
    }
}
