<?php

namespace App\Models;

use App\Enums\ImportExisting;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property ImportType $type
 * @property ImportStatus $status
 * @property ImportExisting $existing
 * @property string $file_name
 * @property string $file_path
 * @property string $file_hash
 * @property list<string>|null $headings
 * @property array<string, int>|null $mapping
 * @property int $total_rows
 * @property array<string, int>|null $preview_counts
 * @property array<string, int>|null $result_counts
 * @property string|null $error_report_path
 * @property string|null $message
 * @property Carbon|null $previewed_at
 * @property Carbon|null $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 *
 * One uploaded import file and what became of it: the import log
 * (ImportService). Nothing is written to the catalog, stock or payables
 * until the run is confirmed, and then all of it or none of it.
 */
class ImportRun extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ImportType::class,
            'status' => ImportStatus::class,
            'existing' => ImportExisting::class,
            'headings' => 'array',
            'mapping' => 'array',
            'total_rows' => 'integer',
            'preview_counts' => 'array',
            'result_counts' => 'array',
            'previewed_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** "12 created, 3 updated, 1 skipped", from the result, else the preview. */
    public function countsSummary(): string
    {
        $counts = $this->result_counts ?? $this->preview_counts ?? [];
        $parts = [];

        foreach (['created' => 'created', 'updated' => 'updated', 'skipped' => 'skipped', 'errors' => 'with errors'] as $key => $label) {
            if (($counts[$key] ?? 0) > 0) {
                $parts[] = "{$counts[$key]} {$label}";
            }
        }

        return $parts === [] ? '—' : implode(', ', $parts);
    }
}
