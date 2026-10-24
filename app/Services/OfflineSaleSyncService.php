<?php

namespace App\Services;

use App\Enums\BillType;
use App\Enums\PaymentMethod;
use App\Exceptions\PosException;
use App\Models\Device;
use App\Models\InvoiceNumberBlock;
use App\Models\Sale;
use App\Models\User;
use App\Services\Data\BatchPick;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Records the sales a till made while offline. Each sale goes through
 * CheckoutService (isOfflineSync) on its own, in the order the till sent
 * them, so one bad sale never holds up the rest. The sale uuid makes every
 * upload safe to repeat: a sale the server already has comes back as a
 * duplicate and is never recorded twice.
 */
class OfflineSaleSyncService
{
    /** A time the till wrote: always with an offset (Z or +05:30), since a bare local time can't be placed in UTC. */
    public const TIME_WITH_OFFSET = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

    public function __construct(private readonly CheckoutService $checkout) {}

    /** Set aside the next block of invoice numbers for the till's offline sales. */
    public function reserveInvoiceBlock(Device $device): InvoiceNumberBlock
    {
        $size = max(1, (int) config('pos.offline.invoice_block_size', 200));

        return DB::transaction(fn (): InvoiceNumberBlock => $device->reserveInvoiceBlock($size));
    }

    /** The owner has looked at a flagged offline sale. The sale itself is never changed. */
    public function markReviewed(Sale $sale, User $user): Sale
    {
        if (! $user->can('sales.review')) {
            throw new PosException('You do not have permission to review sales.');
        }

        if (! $sale->needs_review || $sale->reviewed_at !== null) {
            throw new PosException('This sale is not waiting for review.');
        }

        $sale->forceFill(['reviewed_by' => $user->getKey(), 'reviewed_at' => now()])->save();

        return $sale;
    }

    /**
     * $deviceTime is the till's clock when it sent the upload (ISO 8601 with an
     * offset; null from older clients). How far it is from the server's clock is
     * measured once and stored on every sale of the upload; CheckoutService flags
     * a clock out of tolerance and dates the sale for reports by the server's
     * clock (docs/PHARMACY.md §7.5). A sale time in the future is never rejected,
     * nor one a TIMESTAMP column can't hold (dated when it arrived, flagged).
     *
     * @param  list<array<string, mixed>>  $sales
     * @return list<array{uuid: string|null, status: 'synced'|'duplicate'|'rejected'|'retry', invoice_no: string|null, needs_review: bool, message: string|null}>
     */
    public function sync(Device $device, array $sales, ?string $deviceTime = null): array
    {
        $clockOffset = $deviceTime === null ? null : self::clockOffsetSeconds($deviceTime);

        return array_map(fn (array $sale): array => $this->syncOne($device, $sale, $clockOffset), $sales);
    }

    /**
     * The till's clock minus the server's, in whole seconds (positive = the
     * till is ahead), at most what sales.clock_offset_seconds (INT) holds: a
     * clock some 68 years out is flagged all the same.
     */
    public static function clockOffsetSeconds(string $deviceTime): int
    {
        $tillClock = Carbon::parse($deviceTime);
        $serverClock = now();
        $offset = round(((float) $tillClock->format('U.u')) - ((float) $serverClock->format('U.u')));

        return (int) max(-2_147_483_647, min(2_147_483_647, $offset));
    }

    /**
     * Validation rules for one offline sale. Amounts are minor units, as the till calculated them.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'invoice_no' => ['required', 'string', 'max:30'],
            // Always with an offset (Z or +05:30): a bare local time cannot be placed in UTC.
            'sold_at' => ['required', 'date', 'regex:'.self::TIME_WITH_OFFSET],
            'cashier_id' => ['required', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.variant_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0'],
            'lines.*.line_discount' => ['nullable', 'integer', 'min:0'],
            // Payload v2, all optional (docs/PHARMACY.md §7.1): sales an older till wrote are
            // replayed as before (base unit, the till's location, server FEFO).
            'lines.*.unit_id' => ['nullable', 'integer'],
            'lines.*.location_id' => ['nullable', 'integer'],
            'lines.*.batches' => ['nullable', 'array', 'max:20'],
            'lines.*.batches.*.batch_id' => ['required', 'integer'],
            'lines.*.batches.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'lines.*.prescriber' => ['nullable', 'string', 'max:150'],
            'lines.*.prescription_ref' => ['nullable', 'string', 'max:100'],
            // unit_price was typed by the cashier (a service such as the consultation fee).
            'lines.*.price_override' => ['nullable', 'boolean'],
            // An OPD bill: the consultation plus one Medicines amount over the medicine lines.
            'bill_type' => ['nullable', Rule::enum(BillType::class)],
            'medicines_amount' => ['nullable', 'integer', 'min:0'],
            'sale_discount' => ['nullable', 'integer', 'min:0'],
            'payments' => ['required', 'array', 'min:1', 'max:10'],
            'payments.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'payments.*.amount' => ['required', 'integer', 'min:1'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
            'grand_total' => ['required', 'integer', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'prices_include_tax' => ['nullable', 'boolean'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{uuid: string|null, status: 'synced'|'duplicate'|'rejected'|'retry', invoice_no: string|null, needs_review: bool, message: string|null}
     */
    private function syncOne(Device $device, array $input, ?int $clockOffset): array
    {
        $uuid = is_string($input['uuid'] ?? null) ? $input['uuid'] : null;
        $validator = Validator::make($input, self::rules(), [
            'sold_at.regex' => 'The sale time must include a timezone offset, e.g. 2026-09-16T09:45:30+05:30.',
        ]);

        if ($validator->fails()) {
            return $this->result($uuid, 'rejected', message: $validator->errors()->first());
        }

        $sale = $validator->validated();

        try {
            $recorded = $this->checkout->checkout($this->checkoutData($device, $sale, $clockOffset));
        } catch (PosException $exception) {
            return $this->result($uuid, 'rejected', message: $exception->getMessage());
        } catch (UniqueConstraintViolationException) {
            // The same sale arrived twice at once (a retry overtook the first upload).
            $recorded = Sale::query()->where('uuid', $uuid)->where('device_id', $device->getKey())->first();

            if ($recorded === null) {
                return $this->result($uuid, 'retry', message: 'The sale could not be saved yet. It will be sent again.');
            }
        } catch (\Throwable $exception) {
            report($exception);

            return $this->result($uuid, 'retry', message: 'The sale could not be saved yet. It will be sent again.');
        }

        return $this->result(
            $uuid,
            $recorded->wasRecentlyCreated ? 'synced' : 'duplicate',
            $recorded->invoice_no,
            (bool) $recorded->needs_review,
        );
    }

    /** @param  array<string, mixed>  $sale */
    private function checkoutData(Device $device, array $sale, ?int $clockOffset): CheckoutData
    {
        // Only a cashier of the till's own shop can be named on its sales.
        $cashier = User::query()
            ->where('shop_id', $device->shop_id)
            ->whereKey((int) $sale['cashier_id'])
            ->first() ?? throw new PosException('The cashier on this sale is not a user of this shop.');

        // As the till's clock said (and the receipt printed), even if it was wrong.
        $soldAt = Carbon::parse($sale['sold_at'])->setTimezone(config('app.timezone'));

        return new CheckoutData(
            device: $device,
            cashier: $cashier,
            lines: array_values(array_map(fn (array $line): CartLine => new CartLine(
                (int) $line['variant_id'],
                (float) $line['quantity'],
                (int) ($line['line_discount'] ?? 0),
                (int) $line['unit_price'],
                unitId: isset($line['unit_id']) ? (int) $line['unit_id'] : null,
                locationId: isset($line['location_id']) ? (int) $line['location_id'] : null,
                // The till's own FEFO from its cached batches: honoured where still valid.
                batches: isset($line['batches']) ? BatchPick::fromArray($line['batches']) : null,
                // A typed price is kept when the product allows it; else flagged by CheckoutService.
                unitPriceOverride: ($line['price_override'] ?? false) ? (int) $line['unit_price'] : null,
                tillPriceOverride: (bool) ($line['price_override'] ?? false),
                prescriber: $this->text($line['prescriber'] ?? null),
                prescriptionRef: $this->text($line['prescription_ref'] ?? null),
            ), $sale['lines'])),
            payments: array_values(array_map(fn (array $payment): PaymentInput => new PaymentInput(
                PaymentMethod::from($payment['method']),
                (int) $payment['amount'],
                $payment['reference'] ?? null,
            ), $sale['payments'])),
            saleDiscount: (int) ($sale['sale_discount'] ?? 0),
            uuid: $sale['uuid'],
            customerName: $sale['customer_name'] ?? null,
            customerPhone: $sale['customer_phone'] ?? null,
            notes: $sale['notes'] ?? null,
            isOfflineSync: true,
            soldAt: $soldAt,
            invoiceNo: $sale['invoice_no'],
            tillGrandTotal: (int) $sale['grand_total'],
            tillTaxRate: isset($sale['tax_rate']) ? (float) $sale['tax_rate'] : null,
            tillPricesIncludeTax: isset($sale['prices_include_tax']) ? (bool) $sale['prices_include_tax'] : null,
            billType: isset($sale['bill_type']) ? BillType::from($sale['bill_type']) : BillType::Standard,
            opdMedicinesAmount: isset($sale['medicines_amount']) ? (int) $sale['medicines_amount'] : null,
            clockOffsetSeconds: $clockOffset,
        );
    }

    /** Trimmed text as the till sent it; blank = none. */
    private function text(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : $text;
    }

    /**
     * @param  'synced'|'duplicate'|'rejected'|'retry'  $status
     * @return array{uuid: string|null, status: 'synced'|'duplicate'|'rejected'|'retry', invoice_no: string|null, needs_review: bool, message: string|null}
     */
    private function result(?string $uuid, string $status, ?string $invoiceNo = null, bool $needsReview = false, ?string $message = null): array
    {
        return [
            'uuid' => $uuid,
            'status' => $status,
            'invoice_no' => $invoiceNo,
            'needs_review' => $needsReview,
            'message' => $message,
        ];
    }
}
