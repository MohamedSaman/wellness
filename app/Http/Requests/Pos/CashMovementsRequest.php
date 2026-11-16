<?php

namespace App\Http\Requests\Pos;

/** The till's cash movements of its open shift (branch 18). */
class CashMovementsRequest extends PosRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device' => ['required', 'uuid'],
        ];
    }
}
