<?php

namespace App\Services\Import;

use App\Enums\GoodsReceiptStatus;
use App\Enums\ImportType;
use App\Models\GoodsReceipt;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\SupplierOpeningBalance;
use App\Services\BatchService;
use App\Services\SupplierPayablesService;
use App\Services\SupplierService;
use Illuminate\Support\Str;

/**
 * Supplier opening invoices (purchasing module): what the shop owed each
 * supplier at go-live, invoice by invoice (docs/PHARMACY.md §4.9, as built
 * 6b). Each becomes an opening entry and an open item in supplier payables,
 * through SupplierService::recordOpeningBalance(), as of the import day.
 * The due date defaults to the invoice date + the supplier's payment terms.
 * An invoice number already entered for that supplier is skipped, so the
 * same file can be run again.
 */
final class OpeningInvoicesImport implements ImportDefinition
{
    public function __construct(
        private readonly SupplierService $suppliers,
        private readonly SupplierPayablesService $payables,
        private readonly BatchService $batches,
    ) {}

    public function type(): ImportType
    {
        return ImportType::OpeningInvoices;
    }

    public function sheetName(Shop $shop): string
    {
        return 'Opening invoices';
    }

    public function fields(Shop $shop): array
    {
        $names = array_values(array_map(strval(...), Supplier::query()->where('is_active', true)->orderBy('name')->limit(200)->pluck('name')->all()));

        return [
            new ImportField('supplier', 'Supplier', true, ['Supplier name', 'Vendor', 'Creditor'], 'The supplier\'s name exactly as on file (import suppliers first).', options: $names === [] ? null : $names, width: 30, strictList: false),
            new ImportField('invoice_no', 'Invoice no.', true, ['Invoice number', 'Invoice', 'Bill no', 'Inv no'], 'The supplier\'s invoice number. An invoice number already entered for the supplier is skipped.', text: true, width: 18),
            new ImportField('invoice_date', 'Invoice date', true, ['Date', 'Bill date', 'Inv date'], 'As on the invoice: 15/10/2026 or 2026-10-15. Not in the future.', text: true, width: 14),
            new ImportField('amount', 'Amount owed', true, ['Amount', 'Balance', 'Outstanding', 'Due amount', 'Total'], 'What is still owed on the invoice, in '.$shop->currency.' (1250.50). More than zero.'),
            new ImportField('due_on', 'Due date', aliases: ['Due', 'Due on', 'Payment due'], help: 'Blank: the invoice date + the supplier\'s payment terms. Not before the invoice date.', text: true, width: 14),
            new ImportField('note', 'Note', aliases: ['Notes', 'Remarks'], help: 'Optional.', width: 30),
        ];
    }

    public function instructions(Shop $shop): array
    {
        return [
            'One row per unpaid supplier invoice at go-live; part-paid invoices with what is still owed.',
            'The supplier must already be on file: import suppliers first. Each invoice becomes an opening invoice on the supplier\'s page and appears on the Payables screen.',
            'An invoice number already entered for that supplier is skipped, so the same file can be imported again safely.',
            'The three grey rows are examples: delete them or leave them, they are never imported.',
        ];
    }

    public function examples(Shop $shop): array
    {
        return [
            ['supplier' => 'Example Pharma Distributors (Pvt) Ltd', 'invoice_no' => 'EX-10021', 'invoice_date' => '28/08/2026', 'amount' => 45250.00, 'due_on' => null, 'note' => null],
            ['supplier' => 'Example Pharma Distributors (Pvt) Ltd', 'invoice_no' => 'EX-10077', 'invoice_date' => '30/09/2026', 'amount' => 18900.50, 'due_on' => '15/11/2026', 'note' => 'Agreed later due date'],
            ['supplier' => 'Example Medical Supplies', 'invoice_no' => 'MS/2026/441', 'invoice_date' => '05/10/2026', 'amount' => 7600.00, 'due_on' => null, 'note' => 'Part paid: balance'],
        ];
    }

    public function check(array $rows, ImportContext $context): void
    {
        $suppliers = Supplier::query()->get()->keyBy(fn (Supplier $supplier): string => self::key($supplier->name));
        $entered = SupplierOpeningBalance::query()
            ->whereNotNull('invoice_no')
            ->whereNull('reversal_of_id')
            ->whereDoesntHave('reversal')
            ->get(['supplier_id', 'invoice_no'])
            ->mapWithKeys(fn (SupplierOpeningBalance $entry): array => [$entry->supplier_id.'|'.self::key((string) $entry->invoice_no) => true]);
        $onGrns = GoodsReceipt::query()
            ->where('status', GoodsReceiptStatus::Posted)
            ->whereNotNull('supplier_invoice_no')
            ->get(['supplier_id', 'supplier_invoice_no', 'grn_number'])
            ->mapWithKeys(fn (GoodsReceipt $grn): array => [$grn->supplier_id.'|'.self::key((string) $grn->supplier_invoice_no) => (string) $grn->grn_number]);

        $today = $this->batches->today();
        $seen = [];

        foreach ($rows as $row) {
            $name = $row->text('supplier', 150, required: true);
            $invoiceNo = $row->text('invoice_no', 50, required: true);
            $invoiceDate = $row->date('invoice_date', required: true);
            $amount = $row->money('amount', $context->currencyDecimals(), required: true, positive: true);
            $dueOn = $row->date('due_on');
            $note = $row->text('note', 1000);

            if ($invoiceDate !== null && $invoiceDate->greaterThan($today)) {
                $row->error('Invoice date '.$invoiceDate->format('d/m/Y').' is in the future.');
            }

            if ($dueOn !== null && $invoiceDate !== null && $dueOn->lessThan($invoiceDate)) {
                $row->error('Due date '.$dueOn->format('d/m/Y').' is before the invoice date.');
            }

            $supplier = $name === null ? null : $suppliers->get(self::key($name));

            if ($name !== null && $supplier === null) {
                $row->error("Unknown supplier \"{$name}\": import it with the suppliers template first, or use the name exactly as on file.");
            }

            if ($supplier === null || $invoiceNo === null) {
                continue;
            }

            $key = $supplier->getKey().'|'.self::key($invoiceNo);

            if (isset($seen[$key])) {
                $row->error("Invoice {$invoiceNo} of {$supplier->name} is also on row {$seen[$key]}.");

                continue;
            }

            $seen[$key] = $row->number;

            if (! $supplier->is_active) {
                $row->warn("{$supplier->name} is not active.");
            }

            if (isset($onGrns[$key])) {
                $row->warn("Goods received note {$onGrns[$key]} already carries invoice {$invoiceNo}: make sure it is not owed twice.");
            }

            if (isset($entered[$key])) {
                $row->skip("Opening invoice {$invoiceNo} of {$supplier->name} is already entered: skipped.");

                continue;
            }

            if ($row->hasErrors() || $invoiceDate === null || $amount === null) {
                continue;
            }

            $due = $dueOn ?? $this->payables->dueOn($supplier, $invoiceDate);

            if ($dueOn === null && $due->lessThan($today)) {
                $row->warn('Due on '.$due->format('d/m/Y').' (invoice date + '.(int) $supplier->payment_terms_days.' days): already overdue.');
            }

            $row->action = ImportRow::CREATE;
            $row->data = [
                'supplier_id' => (int) $supplier->getKey(),
                'invoice_no' => $invoiceNo,
                'invoice_date' => $invoiceDate->toDateString(),
                'amount' => $amount,
                'due_on' => $dueOn?->toDateString(),
                'note' => $note,
            ];
        }
    }

    public function commit(array $rows, ImportContext $context, \Closure $progress): void
    {
        foreach ($rows as $done => $row) {
            if ($row->action === ImportRow::CREATE) {
                /** @var Supplier $supplier */
                $supplier = Supplier::query()->findOrFail($row->data['supplier_id']);

                $row->write(fn () => $this->suppliers->recordOpeningBalance(
                    $supplier,
                    $row->data['amount'],
                    $context->user,
                    note: $row->data['note'] ?? $context->note(),
                    invoiceNo: $row->data['invoice_no'],
                    invoiceDate: $row->data['invoice_date'],
                    dueOn: $row->data['due_on'],
                ));
            }

            $progress($done + 1);
        }
    }

    private static function key(string $text): string
    {
        return Str::lower(Str::squish($text));
    }
}
