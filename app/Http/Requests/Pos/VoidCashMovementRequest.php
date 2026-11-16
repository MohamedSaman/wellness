<?php

namespace App\Http\Requests\Pos;

/** Voiding a cash movement (the owner, while its shift is open; branch 18). */
class VoidCashMovementRequest extends PosRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Give the reason for voiding it.'];
    }
}
