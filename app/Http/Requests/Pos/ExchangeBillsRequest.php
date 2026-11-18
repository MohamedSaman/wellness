<?php

namespace App\Http\Requests\Pos;

/** Finding the bill of a return or exchange at the till (branch 19): by invoice number, or the patient's bills. */
class ExchangeBillsRequest extends PosRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && ($this->user()?->can('pos.exchange') ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device' => ['required', 'uuid'],
            'q' => ['nullable', 'string', 'max:50'],
            'patient' => ['nullable', 'uuid'],
        ];
    }
}
