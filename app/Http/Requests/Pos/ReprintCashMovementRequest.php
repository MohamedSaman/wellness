<?php

namespace App\Http\Requests\Pos;

/** A cash movement's slip reprinted from the till; the till is written to the audit log. */
class ReprintCashMovementRequest extends PosRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device' => ['required', 'uuid'],
        ];
    }
}
