<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $user_id
 * @property int|null $label_template_id
 * @property string $template_name
 * @property string $source product | delivery | batches | products | queue
 * @property string|null $source_label
 * @property int $labels_count
 * @property list<array{variant_id: int, unit_id: int|null, batch_id: int|null, batch_no: string|null, expiry: string|null, quantity: int, name: string, unit: string|null, barcode: string|null}> $items
 *
 * One print of labels, the print log (docs/PHARMACY.md §9, branch 7b): who
 * printed which labels, how many, with which template. Opening it again
 * prints the same labels again, with today's names and prices. Never
 * changed; not audited.
 */
class LabelPrint extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'labels_count' => 'integer',
            'items' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('A label print is a log entry and never changes.'));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<LabelTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(LabelTemplate::class, 'label_template_id');
    }

    /** "Panadol 500 mg Tablets (Box of 30) × 5, …" for the log. */
    public function summary(int $limit = 3): string
    {
        $parts = array_map(
            fn (array $item): string => $item['name'].($item['unit'] === null ? '' : " ({$item['unit']})").' × '.$item['quantity'],
            array_slice($this->items, 0, $limit),
        );

        $more = count($this->items) - $limit;

        return implode(', ', $parts).($more > 0 ? ", and {$more} more" : '');
    }
}
