<?php

namespace App\Http\Requests\Pos;

/** Register this browser as a till for offline sales. Owner only by default. */
class RegisterTillRequest extends PosRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->user()?->can('tills.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['device' => ['required', 'uuid']];
    }
}
