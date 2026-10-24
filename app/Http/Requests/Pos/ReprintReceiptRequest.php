<?php

namespace App\Http\Requests\Pos;

/** A reprint from the checkout screen; the till is written to the audit log. */
class ReprintReceiptRequest extends PosRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device' => ['required', 'uuid'],
        ];
    }
}
