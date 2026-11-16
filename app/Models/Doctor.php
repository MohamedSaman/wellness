<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $name
 * @property string|null $phone
 * @property string|null $notes
 * @property bool $is_active
 *
 * A doctor the clinic pays in cash from a till (docs/PHARMACY.md, branch 18).
 * Admin → Doctors (doctors.manage). Deactivated, never deleted: their
 * payments keep them. Audited.
 */
class Doctor extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Doctors are never deleted: deactivate them instead.'));
    }

    /** @return HasMany<CashMovement, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}
