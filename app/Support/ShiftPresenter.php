<?php

namespace App\Support;

use App\Models\Shift;

class ShiftPresenter
{
    /** @return array{uuid: string, opened_at: string, opening_cash: int, opened_by: string} */
    public static function present(Shift $shift): array
    {
        return [
            'uuid' => $shift->uuid,
            'opened_at' => $shift->opened_at->toIso8601String(),
            'opening_cash' => $shift->opening_cash,
            'opened_by' => $shift->user->name,
        ];
    }
}
