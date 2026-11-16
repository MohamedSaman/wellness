<?php

namespace App\Http\Requests\Pos;

use App\Services\Data\NoReceiptLine;
use Illuminate\Validation\Rule;

/** A return without a receipt from the till (branch 18); the rules of the return are NoReceiptReturnService's. */
class StoreNoReceiptReturnRequest extends PosRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.variant_id' => ['required', 'integer'],
            'lines.*.unit_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'lines.*.unit_value' => ['nullable', self::MONEY],
            'lines.*.to' => ['required', Rule::in(['quarantine', 'stock'])],
            'lines.*.batch_no' => ['nullable', 'string', 'max:50'],
            'lines.*.expiry' => ['nullable', 'string', 'max:10'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give the reason for the return.',
            'lines.required' => 'Add the item that came back.',
            'lines.*.unit_value.regex' => 'Enter the value as a number, like 250 or 250.50.',
        ];
    }

    /** @return list<NoReceiptLine> */
    public function lines(): array
    {
        return array_values(array_map(fn (array $line): NoReceiptLine => new NoReceiptLine(
            variantId: (int) $line['variant_id'],
            quantity: (float) $line['quantity'],
            unitId: isset($line['unit_id']) ? (int) $line['unit_id'] : null,
            unitValue: filled($line['unit_value'] ?? null) ? $this->toMinor($line['unit_value']) : null,
            toQuarantine: $line['to'] === 'quarantine',
            batchNo: $line['batch_no'] ?? null,
            expiry: $line['expiry'] ?? null,
        ), (array) $this->validated('lines')));
    }
}
