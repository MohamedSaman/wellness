<?php

namespace App\Http\Requests\Pos;

/** Used to preview ("expected" and the difference) and to close a shift. */
class ShiftCountRequest extends PosRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device' => ['required', 'uuid'],
            'counted_cash' => [$this->isMethod('POST') ? 'required' : 'nullable', self::MONEY],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function countedCash(): ?int
    {
        $counted = $this->validated('counted_cash');

        return filled($counted) ? $this->toMinor($counted) : null;
    }
}
