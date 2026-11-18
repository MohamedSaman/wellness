<?php

namespace App\Http\Requests\Pos;

use App\Enums\ReturnReason;
use App\Models\Sale;
use App\Services\Data\NoReceiptLine;
use App\Services\Data\ReturnLine;
use Illuminate\Validation\Rule;

/**
 * A return or exchange from the till (docs/PHARMACY.md, branch 19): the bill
 * (`sale`, its uuid) and its lines coming back, or without a bill the items
 * (`no_receipt`, owner only), each saying where its stock goes; the reason,
 * note and doctor; and the new items as a cart (no lines: a plain return)
 * with what the patient pays on top of the credit. ExchangeService has the
 * rules; also used, without the cart, for the credit's preview.
 */
class ExchangeRequest extends CartRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && ($this->user()?->can('pos.exchange') ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->cartRules(),
            'device' => ['required', 'uuid'],
            'uuid' => ['nullable', 'uuid'],
            'lines' => ['nullable', 'array', 'max:200'],
            'sale' => ['nullable', 'uuid'],
            'returns' => ['nullable', 'array', 'max:200'],
            'returns.*.sale_item_id' => ['required', 'integer'],
            'returns.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'returns.*.in_base_units' => ['nullable', 'boolean'],
            'returns.*.to' => ['required', Rule::in(['quarantine', 'stock'])],
            'no_receipt' => ['nullable', 'array', 'max:20'],
            'no_receipt.*.variant_id' => ['required', 'integer'],
            'no_receipt.*.unit_id' => ['nullable', 'integer'],
            'no_receipt.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'no_receipt.*.unit_value' => ['nullable', self::MONEY],
            'no_receipt.*.to' => ['required', Rule::in(['quarantine', 'stock'])],
            'no_receipt.*.batch_no' => ['nullable', 'string', 'max:50'],
            'no_receipt.*.expiry' => ['nullable', 'string', 'max:10'],
            'reason' => ['nullable', Rule::in(array_keys(ReturnReason::tillOptions()))],
            'note' => ['nullable', 'string', 'max:500'],
            'doctor_id' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.in' => 'Choose why the items came back.',
            'no_receipt.*.unit_value.regex' => 'Enter the value as a number, like 250 or 250.50.',
        ];
    }

    /** The bill the items came from, in this shop; null without one. */
    public function original(): ?Sale
    {
        $uuid = $this->validated('sale');

        return $uuid === null ? null : (Sale::query()->where('uuid', $uuid)->first() ?? abort(404, 'That bill was not found.'));
    }

    public function reason(): ReturnReason
    {
        return ReturnReason::tryFrom((string) $this->validated('reason')) ?? ReturnReason::NotEffective;
    }

    /**
     * The bill's lines coming back, each to Quarantine or back on sale at $hereId.
     *
     * @return list<ReturnLine>
     */
    public function returnLines(?int $quarantineId, int $hereId): array
    {
        return array_values(array_map(fn (array $line): ReturnLine => new ReturnLine(
            (int) $line['sale_item_id'],
            (float) $line['quantity'],
            (bool) ($line['in_base_units'] ?? false),
            true,
            null,
            $line['to'] === 'stock' ? $hereId : $quarantineId,
        ), (array) ($this->validated('returns') ?? [])));
    }

    /** @return list<NoReceiptLine> */
    public function noReceiptLines(): array
    {
        return array_values(array_map(fn (array $line): NoReceiptLine => new NoReceiptLine(
            variantId: (int) $line['variant_id'],
            quantity: (float) $line['quantity'],
            unitId: isset($line['unit_id']) ? (int) $line['unit_id'] : null,
            unitValue: filled($line['unit_value'] ?? null) ? $this->toMinor($line['unit_value']) : null,
            toQuarantine: $line['to'] === 'quarantine',
            batchNo: $line['batch_no'] ?? null,
            expiry: $line['expiry'] ?? null,
        ), (array) ($this->validated('no_receipt') ?? [])));
    }
}
