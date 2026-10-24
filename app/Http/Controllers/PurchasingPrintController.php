<?php

namespace App\Http\Controllers;

use App\Exceptions\PosException;
use App\Filament\Pages\Reports\Export\ReportDocument;
use App\Filament\Pages\Reports\Export\ReportExporter;
use App\Filament\Pages\Reports\Export\ReportTable;
use App\Filament\Pages\Reports\ReportColumn;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierPayable;
use App\Models\SupplierReturn;
use App\Services\SupplierPayablesService;
use App\Support\CurrentShop;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Printable purchasing documents, plain HTML for the browser's print
 * dialog (?print=1 opens it). Route model binding is tenant-scoped, so
 * another shop's document is a 404; the document's policy decides who may
 * see it. Costs are shown: these are purchasing documents.
 */
class PurchasingPrintController extends Controller
{
    public function order(Request $request, PurchaseOrder $order, CurrentShop $currentShop): View
    {
        $this->authorizeView($request, $order);

        return view('purchasing.print.order', [
            'shop' => $currentShop->getOrFail(),
            'order' => $order->load(['items.variant.product', 'supplier', 'location']),
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    public function receipt(Request $request, GoodsReceipt $receipt, CurrentShop $currentShop): View
    {
        $this->authorizeView($request, $receipt);
        abort_unless($receipt->isPosted(), 404);

        return view('purchasing.print.receipt', [
            'shop' => $currentShop->getOrFail(),
            'receipt' => $receipt->load(['items.variant.product', 'supplier', 'location', 'purchaseOrder', 'poster', 'reversalOf']),
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    public function supplierReturn(Request $request, SupplierReturn $supplierReturn, CurrentShop $currentShop): View
    {
        $this->authorizeView($request, $supplierReturn);

        return view('purchasing.print.return', [
            'shop' => $currentShop->getOrFail(),
            'return' => $supplierReturn->load(['items.variant.product', 'supplier', 'location', 'user']),
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    /**
     * A supplier's statement for ?from=&to= (default: this month so far):
     * the balance brought forward, every GRN, return and payment with the
     * running balance, the balance at the end, and the invoices still open
     * today with their due dates. Saved as PDF from the print dialog, or
     * downloaded as Excel or PDF (?format=xlsx|pdf).
     */
    public function statement(Request $request, Supplier $supplier, CurrentShop $currentShop, SupplierPayablesService $payables, ReportExporter $exporter): View|StreamedResponse
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->can('payables.view') || $user->can('purchasing.manage')), 403);

        $today = $payables->today();

        try {
            $statement = $payables->statement(
                $supplier,
                (string) $request->query('from', $today->startOfMonth()->toDateString()),
                (string) $request->query('to', $today->toDateString()),
            );
        } catch (PosException $exception) {
            abort(422, $exception->getMessage());
        }

        $open = $payables->openPayables((int) $supplier->getKey());
        $credit = $payables->creditLeft((int) $supplier->getKey());

        // Excel and PDF (docs/PHARMACY.md §9, branch 11): the same statement as a report document.
        if (in_array($request->query('format'), ['xlsx', 'pdf'], true)) {
            $document = $this->statementDocument($supplier, $statement, $open, $credit, $today);

            return $request->query('format') === 'xlsx' ? $exporter->xlsxDownload($document) : $exporter->pdfDownload($document);
        }

        return view('purchasing.print.statement', [
            'shop' => $currentShop->getOrFail(),
            'supplier' => $supplier,
            'statement' => $statement,
            'open' => $open,
            'credit' => $credit,
            'today' => $today,
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    /**
     * The statement's lines (from the balance brought forward to the balance
     * at the end) and the invoices unpaid today, as a report document.
     *
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, opening: int, lines: list<array{date: CarbonImmutable, invoice_date: CarbonImmutable|null, document: string, detail: string|null, debit: int, credit: int, balance: int}>, closing: int}  $statement
     * @param  Collection<int, SupplierPayable>  $open
     */
    private function statementDocument(Supplier $supplier, array $statement, Collection $open, int $credit, CarbonImmutable $today): ReportDocument
    {
        $lines = [
            ['date' => $statement['from']->format('d/m/Y'), 'document' => 'Balance brought forward', 'balance' => $statement['opening']],
            ...array_map(fn (array $line): array => [
                'date' => $line['date']->format('d/m/Y'),
                'invoice_date' => $line['invoice_date']?->format('d/m/Y'),
                'document' => $line['document'],
                'detail' => $line['detail'],
                'debit' => $line['debit'] === 0 ? null : $line['debit'],
                'credit' => $line['credit'] === 0 ? null : $line['credit'],
                'balance' => $line['balance'],
            ], $statement['lines']),
        ];

        $unpaid = array_values($open->map(fn (SupplierPayable $payable): array => [
            'document' => $payable->documentLabel(),
            'invoice' => $payable->invoice_no,
            'invoice_date' => $payable->invoice_date?->format('d/m/Y'),
            'due_on' => $payable->due_on->format('d/m/Y'),
            'overdue' => max($payable->daysOverdue($today), 0) ?: null,
            'amount' => $payable->amount,
            'outstanding' => $payable->outstanding(),
        ])->all());

        $period = $statement['from']->format('d/m/Y').' – '.$statement['to']->format('d/m/Y');

        return new ReportDocument('Supplier statement', [
            ['Supplier', $supplier->name],
            ['Period', $period],
            ['Payment terms', $supplier->payment_terms_days === null ? '-' : $supplier->payment_terms_days.' days'],
        ], [
            new ReportTable('Statement', [
                ReportColumn::text('date', 'Date'),
                ReportColumn::text('invoice_date', 'Invoice date'),
                ReportColumn::text('document', 'Document'),
                ReportColumn::text('detail', 'Details'),
                ReportColumn::money('debit', 'Owed'),
                ReportColumn::money('credit', 'Paid / credited'),
                ReportColumn::money('balance', 'Balance', total: false),
            ], $lines, [
                'date' => 'Balance at '.$statement['to']->format('d/m/Y'),
                'debit' => array_sum(array_column($statement['lines'], 'debit')),
                'credit' => array_sum(array_column($statement['lines'], 'credit')),
                'balance' => $statement['closing'],
            ], 'Positive: owed to '.$supplier->name.'. Negative: credit with the supplier.'),
            new ReportTable('Unpaid invoices at '.$today->format('d/m/Y'), [
                ReportColumn::text('document', 'Document'),
                ReportColumn::text('invoice', 'Invoice'),
                ReportColumn::text('invoice_date', 'Invoice date'),
                ReportColumn::text('due_on', 'Due'),
                ReportColumn::integer('overdue', 'Days overdue', total: false),
                ReportColumn::money('amount', 'Amount'),
                ReportColumn::money('outstanding', 'Outstanding'),
            ], $unpaid, $unpaid === [] ? null : [
                'document' => 'Total',
                'amount' => (int) $open->sum('amount'),
                'outstanding' => (int) $open->sum(fn (SupplierPayable $payable): int => $payable->outstanding()),
            ], $credit > 0 ? 'Credit not yet allocated: '.$supplier->shop?->formatMoney($credit).'.' : null),
        ], 'supplier-statement_'.str($supplier->name)->slug().'_'.$statement['from']->toDateString().'_'.$statement['to']->toDateString());
    }

    private function authorizeView(Request $request, Model $document): void
    {
        abort_unless($request->user()?->can('view', $document) ?? false, 403);
    }
}
