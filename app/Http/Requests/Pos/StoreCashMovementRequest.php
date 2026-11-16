<?php

namespace App\Http\Requests\Pos;

use App\Enums\CashMovementCategory;
use App\Enums\CashMovementType;
use Illuminate\Validation\Rule;

/**
 * A paid out, paid in or doctor payment at the till (branch 18). The rules
 * of the movement itself are CashMovementService's.
 */
class StoreCashMovementRequest extends PosRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device' => ['required', 'uuid'],
            'type' => ['required', Rule::enum(CashMovementType::class)],
            'amount' => ['required', self::MONEY],
            'category' => ['nullable', Rule::enum(CashMovementCategory::class)],
            'doctor' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** "2,000.00" as typed: the thousands separators go before validation. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('amount'))) {
            $this->merge(['amount' => str_replace([',', ' '], '', trim($this->input('amount')))]);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.required' => 'Enter the amount.',
            'amount.regex' => 'Enter the amount as a number, like 5000 or 5000.50.',
        ];
    }

    public function movementType(): CashMovementType
    {
        return CashMovementType::from($this->string('type')->toString());
    }

    public function category(): ?CashMovementCategory
    {
        return $this->filled('category') ? CashMovementCategory::from($this->string('category')->toString()) : null;
    }

    public function amount(): int
    {
        return $this->toMinor($this->input('amount'));
    }
}
