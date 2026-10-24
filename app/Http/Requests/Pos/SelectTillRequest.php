<?php

namespace App\Http\Requests\Pos;

class SelectTillRequest extends PosRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['device' => ['required', 'uuid']];
    }
}
