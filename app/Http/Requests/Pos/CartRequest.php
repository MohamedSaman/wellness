<?php

namespace App\Http\Requests\Pos;

use App\Enums\BillType;
use App\Enums\PaymentMethod;
use App\Services\Data\BatchPick;
use App\Services\Data\CartLine;
use App\Services\Data\PaymentInput;
use Illuminate\Validation\Rule;

/**
 * A cart as the till sends it: variant ids, units and quantities, never
 * prices. The one exception is a typed price (`lines.*.price`) for a service
 * that allows it (docs/PHARMACY.md §4.3), which CheckoutService checks.
 */
abstract class CartRequest extends PosRequest
{
    /** @return array<string, mixed> */
    protected function cartRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.variant_id' => ['required', 'integer'],
            'lines.*.unit_id' => ['nullable', 'integer'], // omitted = base unit
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            // A batch the pharmacist picked instead of first expiry first out (pos.batch_override), in base units.
            'lines.*.batches' => ['nullable', 'array', 'max:20'],
            'lines.*.batches.*.batch_id' => ['required', 'integer'],
            'lines.*.batches.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'lines.*.line_discount' => ['nullable', self::MONEY],
            // The location the line's stock comes from; omitted = the till's.
            'lines.*.location_id' => ['nullable', 'integer'],
            // A typed unit price (pos.price_override, products that allow it).
            'lines.*.price' => ['nullable', self::MONEY],
            'lines.*.prescriber' => ['nullable', 'string', 'max:150'],
            'lines.*.prescription_ref' => ['nullable', 'string', 'max:100'],
            'sale_discount' => ['nullable', self::MONEY],
            // An OPD bill: the consultation plus one Medicines amount spread over the medicine lines.
            'bill_type' => ['nullable', Rule::enum(BillType::class)],
            'medicines_amount' => ['nullable', self::MONEY],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'payments' => ['nullable', 'array', 'max:10'],
            'payments.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'payments.*.amount' => ['required', self::MONEY],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return list<CartLine> */
    public function cartLines(): array
    {
        return array_values(array_map(
            fn (array $line): CartLine => new CartLine(
                (int) $line['variant_id'],
                (float) $line['quantity'],
                $this->toMinor($line['line_discount'] ?? 0),
                unitId: isset($line['unit_id']) ? (int) $line['unit_id'] : null,
                locationId: isset($line['location_id']) ? (int) $line['location_id'] : null,
                batches: isset($line['batches']) ? BatchPick::fromArray($line['batches']) : null,
                unitPriceOverride: isset($line['price']) ? $this->toMinor($line['price']) : null,
                prescriber: $line['prescriber'] ?? null,
                prescriptionRef: $line['prescription_ref'] ?? null,
            ),
            $this->validated('lines'),
        ));
    }

    public function saleDiscount(): int
    {
        return $this->toMinor($this->validated('sale_discount') ?? 0);
    }

    public function billType(): BillType
    {
        return BillType::tryFrom((string) $this->validated('bill_type')) ?? BillType::Standard;
    }

    /** The OPD bill's Medicines amount in minor units; null when none was typed. */
    public function medicinesAmount(): ?int
    {
        $amount = $this->validated('medicines_amount');

        return $amount === null ? null : $this->toMinor($amount);
    }

    /** @return list<PaymentInput> */
    public function paymentInputs(): array
    {
        return array_values(array_map(
            fn (array $payment): PaymentInput => new PaymentInput(
                PaymentMethod::from($payment['method']),
                $this->toMinor($payment['amount']),
                $payment['reference'] ?? null,
            ),
            $this->validated('payments') ?? [],
        ));
    }
}
