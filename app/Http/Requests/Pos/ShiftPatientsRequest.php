<?php

namespace App\Http\Requests\Pos;

/** Patients this shift (pharmacy): the till's own open shift only, so a till is all it names. */
class ShiftPatientsRequest extends PosRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device' => ['required', 'uuid'],
        ];
    }
}
