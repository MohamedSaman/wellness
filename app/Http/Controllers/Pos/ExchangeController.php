<?php

namespace App\Http\Controllers\Pos;

use App\Enums\BillType;
use App\Enums\ReturnReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CashMovementsRequest;
use App\Http\Requests\Pos\ExchangeBillsRequest;
use App\Http\Requests\Pos\ExchangeRequest;
use App\Http\Requests\Pos\ReprintCashMovementRequest;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\Exchange;
use App\Models\Patient;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemBatch;
use App\Models\Shift;
use App\Models\User;
use App\Services\BatchService;
use App\Services\Data\ExchangeData;
use App\Services\ExchangeService;
use App\Services\NoReceiptReturnService;
use App\Services\ReceiptService;
use App\Services\ReturnService;
use App\Support\CurrentShop;
use App\Support\Quantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Return / Exchange at the till (pharmacy, pos.exchange; docs/PHARMACY.md
 * branch 19): find the bill (invoice number, this till's recent sales, the
 * patient's bills), tick what came back, preview the credit, record the
 * return with the new items in one go (ExchangeService), print the EXCHANGE
 * receipt; This shift lists the till's returns and exchanges for reprints.
 * Online only.
 */
class ExchangeController extends Controller
{
    /** The till's returns and exchanges in its open shift, and what the dialog offers. */
    public function index(CashMovementsRequest $request, ExchangeService $exchanges): JsonResponse
    {
        $request->validated();
        $till = $request->device() ?? abort(422);
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('pos.exchange'), 403);
        $shift = Shift::query()->where('device_id', $till->getKey())->whereNull('closed_at')->latest('opened_at')->first();
        [$quarantine, $here] = $exchanges->destinations($till);

        return response()->json([
            'exchanges' => $shift === null ? [] : Exchange::query()->with(['user', 'originalSale', 'newSale', 'noReceiptReturn'])->where('shift_id', $shift->getKey())->orderByDesc('id')->get()
                ->map(fn (Exchange $exchange): array => $this->summary($exchange))->values(),
            'reasons' => collect(ReturnReason::tillOptions())->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values(),
            'doctors' => Doctor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'quarantine' => $quarantine?->name,
            'here' => $here->name,
            'cash_limit' => $exchanges->cashLimitFor($user),
            'max_days' => $exchanges->maxDays(),
            'can_late' => $user->can('pos.exchange.late'),
            'can_no_receipt' => $user->can('returns.no_receipt'),
        ]);
    }

    /** Bills by invoice number (any till of the shop) or the patient's bills, newest first. */
    public function bills(ExchangeBillsRequest $request, ExchangeService $exchanges): JsonResponse
    {
        $input = $request->validated();
        /** @var User $user */
        $user = $request->user();
        $search = trim((string) ($input['q'] ?? ''));
        $patient = isset($input['patient']) ? Patient::query()->where('uuid', $input['patient'])->first() : null;

        if ($search === '' && $patient === null) {
            return response()->json(['bills' => []]);
        }

        $sales = Sale::query()
            ->with(['device', 'patient'])
            ->when($patient !== null, fn ($query) => $query->where('patient_id', $patient?->getKey()))
            ->when($search !== '', fn ($query) => $query->where('invoice_no', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderByRaw('CASE WHEN invoice_no = ? THEN 0 ELSE 1 END', [$search])
            ->latest('sold_at')
            ->limit(20)
            ->get();

        return response()->json(['bills' => $sales->map(fn (Sale $sale): array => $this->bill($sale, $user, $exchanges))->values()]);
    }

    /** One bill with its lines and what can still come back of each. */
    public function show(ExchangeBillsRequest $request, Sale $sale, ExchangeService $exchanges): JsonResponse
    {
        $request->validated();
        /** @var User $user */
        $user = $request->user();
        $sale->load(['items.batches', 'items.returnItems', 'items.variant.product', 'device', 'patient']);
        $today = app(BatchService::class)->today()->toDateString();

        return response()->json([
            'bill' => [
                ...$this->bill($sale, $user, $exchanges),
                'lines' => $sale->bill_type === BillType::Opd ? [] : $sale->items->map(function (SaleItem $item) use ($today): array {
                    $returnable = Quantity::normalize((float) $item->quantity - (float) $item->returned_quantity);
                    $returnableBase = Quantity::normalize((float) $item->base_quantity - (float) $item->returnItems->sum('base_quantity'));
                    $left = $item->batches->filter(fn (SaleItemBatch $piece): bool => $piece->remaining() > 0);
                    $tracks = $item->variant->product->tracksBatches();

                    return [
                        'id' => (int) $item->getKey(),
                        'description' => $item->description,
                        'sku' => $item->sku,
                        'strength' => $item->strength,
                        'unit_name' => $item->unit_name,
                        'base_unit_name' => $item->base_unit_name,
                        'unit_factor' => (float) $item->unit_factor,
                        'quantity' => (float) $item->quantity,
                        'returnable' => $returnable,
                        'returnable_base' => $returnableBase,
                        'line_total' => $item->line_total,
                        'track_stock' => (bool) $item->variant->product->track_stock,
                        // Back on sale needs a known batch that has not expired (StockService::returnTo()).
                        'stock_note' => match (true) {
                            ! $tracks => null,
                            $left->isEmpty() || $left->contains(fn (SaleItemBatch $piece): bool => (bool) $piece->is_unallocated) => 'Batch not known: Quarantine only',
                            $left->contains(fn (SaleItemBatch $piece): bool => $piece->expiry_date !== null && $piece->expiry_date->toDateString() <= $today) => 'Expired batch: Quarantine only',
                            default => null,
                        },
                        'batches' => $left->map(fn (SaleItemBatch $piece): array => [
                            'batch_no' => $piece->is_unallocated ? null : $piece->batch_no,
                            'expiry' => $piece->expiry_date?->format('m/Y'),
                            'remaining' => $piece->remaining(),
                        ])->values(),
                    ];
                })->filter(fn (array $line): bool => $line['returnable'] > 0)->values(),
            ],
        ]);
    }

    /** What the ticked lines (or the items without a bill) give back as credit, recording nothing. */
    public function preview(ExchangeRequest $request, ExchangeService $exchanges, ReturnService $returns, NoReceiptReturnService $noReceipt): JsonResponse
    {
        $request->validated();
        $till = $request->device() ?? abort(422);
        $sale = $request->original();

        if ($sale === null) {
            $preview = $noReceipt->preview($request->noReceiptLines());

            return response()->json(['credit' => $preview['total'], 'lines' => $preview['lines']]);
        }

        [$quarantine, $here] = $exchanges->destinations($till);
        $preview = $returns->preview($sale, $request->returnLines($quarantine?->getKey(), (int) $here->getKey()));

        return response()->json(['credit' => $preview['total'], 'lines' => $preview['lines']]);
    }

    public function store(ExchangeRequest $request, ExchangeService $exchanges): JsonResponse
    {
        $input = $request->validated();
        $till = $request->device() ?? abort(422);
        /** @var User $user */
        $user = $request->user();
        [$quarantine, $here] = $exchanges->destinations($till);

        $exchange = $exchanges->create(new ExchangeData(
            till: $till,
            user: $user,
            uuid: $input['uuid'] ?? (string) Str::uuid(),
            original: $request->original(),
            returnLines: $request->returnLines($quarantine?->getKey(), (int) $here->getKey()),
            noReceiptLines: $request->noReceiptLines(),
            reason: $request->reason(),
            note: $input['note'] ?? null,
            doctorId: isset($input['doctor_id']) ? (int) $input['doctor_id'] : null,
            newLines: $request->cartLines(),
            saleDiscount: $request->saleDiscount(),
            payments: $request->paymentInputs(),
            patient: $request->patientInput(),
        ));

        $exchange->load(['user', 'originalSale', 'newSale', 'noReceiptReturn']);

        return response()->json([
            'exchange' => [
                ...$this->summary($exchange),
                'change_due' => (int) ($exchange->newSale->change_due ?? 0),
            ],
            'receipt_url' => route('pos.exchanges.receipt', $exchange),
        ], 201);
    }

    public function reprint(ReprintCashMovementRequest $request, Exchange $exchange, ExchangeService $exchanges): JsonResponse
    {
        $request->validated();
        /** @var User $user */
        $user = $request->user();
        abort_unless($exchanges->mayRead($user, $exchange), 403);
        $entry = $exchanges->recordReprint($exchange, $user, $request->device());

        return response()->json([
            'receipt_url' => route('pos.exchanges.reprinted', ['exchange' => $exchange, 'reprint' => $entry->getKey()]),
        ]);
    }

    /** The receipt as first printed: never marked. */
    public function receipt(Request $request, Exchange $exchange, ExchangeService $exchanges): Response
    {
        abort_unless($exchanges->mayRead($request->user(), $exchange), 403);

        return self::receiptPage($exchange, $exchanges);
    }

    /** A logged reprint, marked REPRINT: only for the user who reprinted it. */
    public function reprinted(Request $request, Exchange $exchange, int $reprint, ExchangeService $exchanges): Response
    {
        $entry = $exchanges->findReprint($exchange, $reprint);

        abort_if($entry === null || (int) $entry->user_id !== (int) $request->user()?->getKey(), 404);
        abort_unless($exchanges->mayRead($request->user(), $exchange), 403);

        return self::receiptPage($exchange, $exchanges, $entry);
    }

    /** @internal also renders the admin's reprints */
    public static function receiptPage(Exchange $exchange, ExchangeService $exchanges, ?AuditLog $reprint = null): Response
    {
        $receipts = app(ReceiptService::class);
        $kind = $exchange->isPlainReturn() ? 'return' : 'exchange';

        return Inertia::render('pos/Receipt', [
            'title' => ($reprint === null ? ucfirst($kind).' ' : "Reprint {$kind} ").$exchange->number,
            'shop' => $receipts->shop(),
            'sale' => null,
            'thermal' => $receipts->thermal('exchange', $exchanges->receipt($exchange, $reprint)),
            'invoiceUrl' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function bill(Sale $sale, User $user, ExchangeService $exchanges): array
    {
        $timezone = (string) (app(CurrentShop::class)->getOrFail()->timezone ?: config('app.timezone'));

        return [
            'uuid' => (string) $sale->uuid,
            'invoice_no' => $sale->invoice_no,
            'sold_at' => $sale->sold_at->setTimezone($timezone)->format('Y-m-d H:i'),
            'till' => (string) $sale->device?->code,
            'bill_type' => $sale->bill_type->value,
            'status' => $sale->status->value,
            'grand_total' => $sale->grand_total,
            'age_days' => $exchanges->ageInDays($sale),
            'patient' => $sale->patient === null ? null : ['uuid' => (string) $sale->patient->uuid, 'name' => $sale->patient->name],
            'refusal' => $exchanges->refusal($sale, $user),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(Exchange $exchange): array
    {
        $timezone = (string) (app(CurrentShop::class)->getOrFail()->timezone ?: config('app.timezone'));

        return [
            'uuid' => (string) $exchange->uuid,
            'number' => $exchange->number,
            'kind' => $exchange->isPlainReturn() ? 'return' : 'exchange',
            'original_invoice_no' => $exchange->originalSale->invoice_no ?? $exchange->noReceiptReturn?->number,
            'new_invoice_no' => $exchange->newSale?->invoice_no,
            'credit' => $exchange->credit_total,
            'new_total' => $exchange->new_total,
            'patient_paid' => $exchange->patient_paid,
            'cash_refunded' => $exchange->cash_refunded,
            'reason' => $exchange->reason->label(),
            'user' => (string) $exchange->user?->name,
            'at' => $exchange->exchanged_at->setTimezone($timezone)->format('Y-m-d H:i'),
        ];
    }
}
