<?php

namespace App\Models;

use App\Enums\ImportType;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/**
 * @property ImportType $type
 * @property array{headings: list<string>, fields: array<string, string>} $mapping the file's headings (normalized) and field key => the heading it was matched to
 *
 * The column mapping a shop saved for an import type (ImportService): a file
 * with the same headings gets it exactly; another file gets it where its
 * headings are there.
 */
class ImportMapping extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ImportType::class,
            'mapping' => 'array',
        ];
    }
}
