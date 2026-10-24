<?php

namespace App\Http\Requests\Pos;

class SearchItemsRequest extends PosRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device' => ['required', 'uuid'],
            'q' => ['nullable', 'string', 'max:100'],
            'make_id' => ['nullable', 'integer'],
            'model_id' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer', 'between:1900,2100'],
        ];
    }
}
