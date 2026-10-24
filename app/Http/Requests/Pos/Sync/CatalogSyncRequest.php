<?php

namespace App\Http\Requests\Pos\Sync;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class CatalogSyncRequest extends TillSyncRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'since' => ['nullable', 'date'],
            'after' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function since(): ?CarbonInterface
    {
        $since = $this->validated('since');

        return $since === null ? null : Carbon::parse($since)->setTimezone(config('app.timezone'));
    }

    public function afterId(): int
    {
        return (int) ($this->validated('after') ?? 0);
    }
}
