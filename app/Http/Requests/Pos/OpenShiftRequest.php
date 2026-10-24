<?php

namespace App\Http\Requests\Pos;

class OpenShiftRequest extends PosRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device' => ['required', 'uuid'],
            'opening_cash' => ['required', self::MONEY],
        ];
    }

    public function openingCash(): int
    {
        return $this->toMinor($this->validated('opening_cash'));
    }
}
