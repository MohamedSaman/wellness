<?php

namespace App\Http\Requests\Pos;

/** The recent sales panel (F6): this till's sales, optionally searched by invoice number. */
class RecentSalesRequest extends PosRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device' => ['required', 'uuid'],
            'q' => ['nullable', 'string', 'max:30'],
        ];
    }
}
