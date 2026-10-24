<?php

namespace App\Http\Requests\Pos;

use Illuminate\Contracts\Validation\Validator;

class QuoteRequest extends CartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->cartRules(),
            // Optional: with the till, units are checked at its location and batch lines show their batches.
            'device' => ['nullable', 'uuid'],
        ];
    }

    /** A till, when one is named, must be one the user may use; a quote without one prices the cart only. */
    public function withValidator(Validator $validator): void
    {
        if ($this->filled('device')) {
            parent::withValidator($validator);
        }
    }
}
