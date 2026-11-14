<?php

namespace App\Http\Requests\Pos;

/** Shift summaries at the till (pharmacy, branch 17): this till's recently closed shifts, so a till is all it names. */
class ClosedShiftsRequest extends PosRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device' => ['required', 'uuid'],
        ];
    }
}
