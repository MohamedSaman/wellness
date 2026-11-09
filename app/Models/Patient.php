<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use App\Support\PatientAge;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $uuid
 * @property string $name
 * @property Carbon|null $birth_date
 * @property bool $birth_date_estimated worked out from an age, not told
 * @property string|null $notes
 *
 * A patient of the clinic (docs/PHARMACY.md, branch 13): one record shared
 * by every till and location, linked to their OPD and pharmacy bills
 * (sales.patient_id). Created at the till, online or offline (the till
 * makes the uuid); changed through PatientService. Never deleted.
 */
class Patient extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'birth_date_estimated' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Patients are never deleted: their bills keep them.'));
    }

    /** Age in months on a day (the shop's today when omitted); null without a date of birth. */
    public function ageMonths(?CarbonInterface $day = null): ?int
    {
        return PatientAge::at($this->birth_date, $day ?? Carbon::now($this->shop?->timezone ?: config('app.timezone')));
    }

    /**
     * Name contains every word typed, in any order ("perera nim" finds Nimal Perera).
     *
     * @param  Builder<Patient>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        foreach (preg_split('/\s+/', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $query->where('name', 'like', '%'.addcslashes($word, '%_\\').'%');
        }
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
