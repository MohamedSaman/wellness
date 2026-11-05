<?php

namespace App\Http\Requests\Pos;

/**
 * A till preparing itself for offline sales. Open to whoever may sell on the
 * till (PosRequest); what decides is the owner's approval of the till
 * (TillOfflineService::claim()).
 */
class PrepareTillRequest extends PosRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['device' => ['required', 'uuid']];
    }
}
