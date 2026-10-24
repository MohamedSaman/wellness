<?php

namespace App\Http\Requests\Pos;

class CheckoutRequest extends CartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->cartRules(),
            'device' => ['required', 'uuid'],
            'uuid' => ['required', 'uuid'],
            'payments' => ['required', 'array', 'min:1', 'max:10'],
        ];
    }
}
