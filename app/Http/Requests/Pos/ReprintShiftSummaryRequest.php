<?php

namespace App\Http\Requests\Pos;

use App\Services\ShiftSummaryService;
use Illuminate\Validation\Rule;

/** A shift summary reprinted from the till; the till is written to the audit log. */
class ReprintShiftSummaryRequest extends PosRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device' => ['required', 'uuid'],
            'format' => ['sometimes', 'string', Rule::in(app(ShiftSummaryService::class)->formats())],
        ];
    }

    public function printFormat(): string
    {
        return $this->string('format', 'thermal')->toString();
    }
}
