<?php

namespace App\Http\Requests\Pos\Sync;

use App\Services\OfflineSaleSyncService;

/**
 * A batch of offline sales, oldest first. Only the envelope is checked here;
 * each sale is validated on its own so one bad sale cannot block the others.
 * `device_time` is the till's clock when it sent the batch (docs/PHARMACY.md
 * §7.5); older clients leave it out.
 */
class SyncSalesRequest extends TillSyncRequest
{
    public const MAX_SALES = 50;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sales' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_SALES],
            'sales.*' => ['required', 'array'],
            'device_time' => ['nullable', 'string', 'date', 'regex:'.OfflineSaleSyncService::TIME_WITH_OFFSET],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'device_time.regex' => 'The till\'s clock must include a timezone offset, e.g. 2026-09-16T09:45:30+05:30.',
        ];
    }

    /** @return list<array<string, mixed>> */
    public function sales(): array
    {
        return array_values($this->validated('sales'));
    }

    public function deviceTime(): ?string
    {
        $time = $this->validated('device_time');

        return is_string($time) ? $time : null;
    }
}
